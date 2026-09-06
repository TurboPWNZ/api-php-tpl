<?php

namespace Api\components\payment;

use Api\components\Log;
use Api\db\Payment as DbPayment;
use Api\components\payment\Provider;

class Payment
{
    protected string $providerClass;
    protected Provider $provider;
    protected DbPayment $paymentTable;

    public function __construct(string $providerName = 'NOWPayments')
    {
        $providerClass = "Api\\components\\payment\\providers\\" . ucfirst($providerName);

        if (!class_exists($providerClass)) {
            throw new \Exception("Provider {$providerName} not found");
        }
        
        $this->providerClass = $providerClass;
        $this->provider = new $providerClass();
        $this->paymentTable = new DbPayment();
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

        if ($verify) {
            list($orderId, $amount, $message, $status) = $instance->provider->getOrderState($data);

            $payment = $instance->getPayment($orderId);

            // Платеж уже был завершен.
            if (in_array($payment['status'], [\Api\db\Payment::STATUS_COMPLETED, \Api\db\Payment::STATUS_FAILED])) {

                Log::get(Log::PAYMENT)->error('Payment is already completed');
                return false;
            }

            switch ($status) {
                case \Api\db\Payment::STATUS_PENDING:
                    $instance->processPaymentProcessed($orderId, $message);
                    break;
                case \Api\db\Payment::STATUS_COMPLETED:
                    $instance->processPaymentSuccess($orderId, $amount, $payment['user_id']);
                    break;
                case \Api\db\Payment::STATUS_FAILED:
                    $instance->processPaymentFailed($orderId);
            }

            Log::get(Log::PAYMENT)->info('Payment status is ' . $status);

            return true;
        }

        Log::get(Log::PAYMENT)->error('Fail signature verification');
        return false;
    }

    public function createPayment(float $amount, string $currency, int $userId, string $description = ''): array
    {
        $orderId = $this->generateOrderId();
        
        $dbInvoice = $this->paymentTable->insert([
            'order_id' => $orderId,
            'user_id' => $userId,
            'amount' => $amount,
            'currency' => $currency,
            'description' => $description,
            'provider' => $this->providerClass,
            'status' => 'pending'
        ]);

        if (!$dbInvoice) {
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
            $this->paymentTable->update(
                'order_id = :order_id',
                [
                    'order_id' => $orderId,
                    'message' => $providerInvoice['error'],
                    'status' => 'failed'
                ]
            );
            
            return [
                'success' => false,
                'error' => $providerInvoice['error'] ?? 'Failed to create invoice',
                'details' => $providerInvoice['details'] ?? null
            ];
        }

        $updateResult = $this->paymentTable->update(
            'order_id = :order_id',
            [
                'order_id' => $orderId,
                'provider_invoice_id' => $providerInvoice['invoice_id'],
                'invoice_url' => $providerInvoice['invoice_url'],
                'status' => 'pending'
            ]
        );

        if (!$updateResult) {
            return [
                'success' => false,
                'error' => 'Failed to update payment record'
            ];
        }

        return [
            'success' => true,
            'order_id' => $orderId,
            'invoice_url' => $providerInvoice['invoice_url'],
            'amount' => $amount,
            'currency' => $currency
        ];
    }

    public function checkStatus(string $orderId): array
    {
        $payment = $this->paymentTable->find('order_id = :orderId', ['orderId' => $orderId]);
        
        if (!$payment) {
            return [
                'success' => false,
                'error' => 'Payment not found'
            ];
        }

        if (!$payment['provider_invoice_id']) {
            return [
                'success' => false,
                'error' => 'Provider invoice ID not found'
            ];
        }

        $check = $this->provider->checkInvoice($payment['provider_invoice_id']);

        if (!$check['success']) {
            return [
                'success' => false,
                'error' => 'Failed to check invoice status'
            ];
        }

        $invoice = $check['data'];
        
        if (($invoice['status'] ?? '') === 'success' && $payment['status'] === 'pending') {
            $this->processPaymentSuccess($payment, $invoice);
        }

        return [
            'success' => true,
            'status' => $payment['status'],
            'provider_status' => $invoice['status'] ?? 'unknown'
        ];
    }

    protected function processPaymentProcessed(string $orderId, string $message): void
    {
        $this->paymentTable->update(
            'order_id = :order_id',
            [
                'order_id' => $orderId,
                'status' => \Api\db\Payment::STATUS_PENDING,
                'message' => $message
            ]
        );
    }
    protected function processPaymentFailed(string $orderId): void
    {
        $this->paymentTable->update(
            'order_id = :order_id',
            [
                'order_id' => $orderId,
                'status' => \Api\db\Payment::STATUS_FAILED,
                'completed_at' => date('Y-m-d H:i:s')
            ]
        );
    }

    protected function processPaymentSuccess(string $orderId, int $amount, int $userId): void
    {
        $this->paymentTable->update(
            'order_id = :order_id',
            [
                'order_id' => $orderId,
                'status' => \Api\db\Payment::STATUS_COMPLETED,
                'completed_at' => date('Y-m-d H:i:s')
            ]
        );

        $this->provider->updateAccountBalance($userId, $amount);
    }

    protected function generateOrderId(): string
    {
        return 'PAY-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    }

    public function getPayment(string $orderId): ?array
    {
        return $this->paymentTable->find('order_id = :orderId', ['orderId' => $orderId]);
    }

    public static function getPaymentsByUser(int $userId, int $limit = 10, int $offset = 0): array
    {
        $instance = new self();
        return $instance->paymentTable->getPaymentsByUser($userId, $limit, $offset);
    }
}
