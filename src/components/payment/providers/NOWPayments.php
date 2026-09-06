<?php

namespace Api\components\payment\providers;

use Api\components\Log;
use Api\components\payment\Provider;
use Api\Configurator;
use Api\db\Payment;

ini_set('serialize_precision', -1);

class NOWPayments extends Provider
{
    protected static $pendingStates = [
        'waiting', 'confirming', 'confirmed', 'sending'
    ];

    protected static $completedStates = [
        'finished', 'partially_paid'
    ];

    protected static $failedStates = [
        'expired', 'failed', 'refunded', 'wrong_asset_confirmed'
    ];

    protected string $name = 'NOWPayments';
    protected string $baseUrl = 'https://api.nowpayments.io/v1';

    public function createInvoice(float $amount, string $currency, int $userId, string $orderId, string $description): array
    {
        $config = Configurator::getConfig();
        $data = [
            'price_amount' => $amount,
            'price_currency' => $currency,
            'order_id' => $orderId,
            'order_description' => $description,
            'ipn_callback_url' => $config['payment']['providers']['NOWPayments']['invoice']['ipn_callback_url'],
            'success_url' => $config['payment']['providers']['NOWPayments']['invoice']['success_url'],
            'cancel_url' => $config['payment']['providers']['NOWPayments']['invoice']['cancel_url']
        ];

        $ch = curl_init($this->baseUrl . '/invoice');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'x-api-key: ' . $this->apiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 && $httpCode !== 201) {
            return [
                'success' => false,
                'error' => 'Failed to create invoice',
                'details' => $response
            ];
        }

        $invoice = json_decode($response, true);

        /**
         * {
         * "id": "6208649120",
         * "token_id": "5011921708",
         * "order_id": null,
         * "order_description": null,
         * "price_amount": "10",
         * "price_currency": "USD",
         * "pay_currency": null,
         * "ipn_callback_url": "https://domain.com/payment/notyfication/NOWPayments",
         * "invoice_url": "https://nowpayments.io/payment/?iid=6208649120",
         * "success_url": null,
         * "cancel_url": null,
         * "customer_email": null,
         * "partially_paid_url": null,
         * "payout_currency": null,
         * "created_at": "2026-08-03T18:09:53.006Z",
         * "updated_at": "2026-08-03T18:09:53.006Z",
         * "is_fixed_rate": false,
         * "is_fee_paid_by_user": false,
         * "source": null,
         * "collect_user_data": false
         * }
         */
        return [
            'success' => true,
            'invoice_id' => $invoice['id'] ?? null,
            'order_id' => $invoice['order_id'] ?? null,
            'invoice_url' => $invoice['invoice_url'] ?? null,
            'price_amount' => $invoice['price_amount'] ?? null,
            'price_currency' => $invoice['price_currency'] ?? null,
            'created_at' => $invoice['created_at'] ?? null
        ];
    }

    public function getOrderState(array $payload): array
    {
        $data = json_decode($payload['rawBody'], true);

        $paymentStatus = match (true) {
            in_array($data['payment_status'], self::$pendingStates, true)   => Payment::STATUS_PENDING,
            in_array($data['payment_status'], self::$completedStates, true) => Payment::STATUS_COMPLETED,
            in_array($data['payment_status'], self::$failedStates, true)    => Payment::STATUS_FAILED,
            default => Payment::STATUS_PENDING,
        };

        return [
            $data['order_id'],
            $data['price_amount'],
            $data['outcome_amount'] . $data['outcome_currency'] . $data['payment_status'],
            $paymentStatus,
        ];
    }

    public function verifyCallback(array $payload): bool
    {
        if (!isset($payload['rawBody']) || !isset($payload['receivedSignature'])) {
            return false;
        }

        $rawBody = $payload['rawBody'];
        $receivedSignature = $payload['receivedSignature'];

        $data = json_decode($rawBody, true);

        if (!is_array($data)) {
            return false;
        }

        self::recursiveKsort($data);

        $json = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $config = Configurator::getConfig();
        $expectedSignature = hash_hmac(
            'sha512',
            $json,
            $config['payment']['providers']['NOWPayments']['ipnKey']
        );

        return hash_equals(
            strtolower($expectedSignature),
            strtolower($receivedSignature)
        );
    }

    private static function recursiveKsort(array &$array): void
    {
        ksort($array);

        foreach ($array as &$value) {
            if (is_array($value)) {
                self::recursiveKsort($value);
            }
        }
    }
}
