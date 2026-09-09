<?php

namespace Api\components\payment;

use Api\components\Log;
use Api\db\Payment as DbPayment;
use Api\components\payment\Provider;

class Payment
{
    protected string $providerClass;
    protected Provider $provider;

    public function __construct(string $providerName = 'NOWPayments')
    {
        $providerClass = "Api\\components\\payment\\providers\\" . ucfirst($providerName);

        if (!class_exists($providerClass)) {
            throw new \Exception("Provider {$providerName} not found");
        }

        $this->providerClass = $providerClass;
        $this->provider = new $providerClass();
    }

    /**
     * @throws \Exception
     */
    public static function create(string $providerName, float $amount, int $userId, string $currency = 'USD', string $description = ''): array
    {
        $instance = new self($providerName);
        return $instance->createPayment($amount, $currency, $userId, $description);
    }

    public static function notification(string $providerName, array $data)
    {
        $instance = new self($providerName);

        $verify = $instance->provider->verifyCallback($data);

        if (!$verify) {
            Log::get(Log::PAYMENT)->error('Fail signature verification', ['provider' => $providerName]);
            return false;
        }

        [$orderId, $amount, $message, $status] = $instance->provider->getOrderState($data);

        // Не любая нотификация относится к смене статуса заказа (например,
        // pre_checkout_query у Stars отвечается провайдером самостоятельно
        // внутри getOrderState() и не несёт orderId сюда — тут просто нечего делать).
        if (!$orderId) {
            Log::get(Log::PAYMENT)->info('Payment notification: no order id, nothing to update', [
                'provider' => $providerName,
                'message' => $message,
            ]);
            return true;
        }

        $payment = $instance->getPayment($orderId);

        if ($payment === null) {
            Log::get(Log::PAYMENT)->error('Payment notification: order not found', ['order_id' => $orderId]);
            return false;
        }

        // Платеж уже был завершен.
        if (in_array($payment->status, [DbPayment::STATUS_COMPLETED, DbPayment::STATUS_FAILED], true)) {
            Log::get(Log::PAYMENT)->error('Payment is already completed', ['order_id' => $orderId]);
            return false;
        }

        switch ($status) {
            case DbPayment::STATUS_PENDING:
                $instance->processPaymentProcessed($payment, $message);
                break;
            case DbPayment::STATUS_COMPLETED:
                $instance->processPaymentSuccess($payment, $amount);
                break;
            case DbPayment::STATUS_FAILED:
                $instance->processPaymentFailed($payment);
                break;
        }

        Log::get(Log::PAYMENT)->info('Payment status is ' . $status, ['order_id' => $orderId]);

        return true;
    }

    public function createPayment(float $amount, string $currency, int $userId, string $description = ''): array
    {
        $orderId = $this->generateOrderId();

        $dbPayment = DbPayment::create([
            'order_id' => $orderId,
            'user_id' => $userId,
            'amount' => $amount,
            'currency' => $currency,
            'provider' => $this->providerClass,
            'status' => DbPayment::STATUS_PENDING,
            'payload' => ['description' => $description],
        ]);

        if (!$dbPayment) {
            return [
                'success' => false,
                'error' => 'Failed to create payment record'
            ];
        }

        $providerInvoice = $this->provider->createInvoice(
            $amount,
            $currency,
            $userId,
            $orderId,
            $description
        );

        if (!$providerInvoice['success']) {
            $dbPayment->status = DbPayment::STATUS_FAILED;
            $dbPayment->payload = array_merge($dbPayment->payload ?? [], [
                'message' => $providerInvoice['error'] ?? null,
            ]);
            $dbPayment->save();

            return [
                'success' => false,
                'error' => $providerInvoice['error'] ?? 'Failed to create invoice',
                'details' => $providerInvoice['details'] ?? null
            ];
        }

        $dbPayment->payload = array_merge($dbPayment->payload ?? [], [
            'provider_invoice_id' => $providerInvoice['invoice_id'] ?? null,
            'invoice_url' => $providerInvoice['invoice_url'] ?? null,
        ]);
        $dbPayment->save();

        return [
            'success' => true,
            'order_id' => $orderId,
            'invoice_url' => $providerInvoice['invoice_url'],
            'amount' => $amount,
            'currency' => $currency
        ];
    }

    protected function processPaymentProcessed(DbPayment $payment, string $message): void
    {
        $payment->status = DbPayment::STATUS_PENDING;
        $payment->payload = array_merge($payment->payload ?? [], ['message' => $message]);
        $payment->save();
    }

    protected function processPaymentFailed(DbPayment $payment): void
    {
        $payment->status = DbPayment::STATUS_FAILED;
        $payment->save();
    }

    protected function processPaymentSuccess(DbPayment $payment, float $amount): void
    {
        // Атомарный "захват" платежа в completed: если нотификация придёт повторно
        // (провайдер ретраит на таймаут/не-200 ответ), второй раз affected=0
        // и баланс повторно не начислится.
        $claimed = DbPayment::where('id', $payment->id)
            ->where('status', DbPayment::STATUS_PENDING)
            ->update(['status' => DbPayment::STATUS_COMPLETED]);

        if ($claimed === 0) {
            Log::get(Log::PAYMENT)->info('Payment already completed (race), skipping balance update', [
                'order_id' => $payment->order_id,
            ]);
            return;
        }

        $credited = $this->provider->updateAccountBalance((int)$payment->user_id, $amount);

        if (!$credited) {
            Log::get(Log::PAYMENT)->error('Failed to credit balance after payment', [
                'order_id' => $payment->order_id,
                'user_id' => $payment->user_id,
            ]);
        }
    }

    protected function generateOrderId(): string
    {
        return 'PAY-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    }

    public function getPayment(string $orderId): ?DbPayment
    {
        return DbPayment::where('order_id', $orderId)->first();
    }

    public static function getPaymentsByUser(int $userId, int $limit = 10, int $offset = 0): array
    {
        return DbPayment::getPaymentsByUser($userId, $limit, $offset);
    }
}
