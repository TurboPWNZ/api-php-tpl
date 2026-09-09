<?php

namespace Api\app\controllers;

use Api\components\payment\Payment;
use Api\Configurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class PaymentController
{
    public function createInvoice(Request $request, string $provider): JsonResponse
    {
        $config = Configurator::getConfig();
        $data = $request->toArray();

        if (empty($data['amount'])) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['Amount is required']
            ]);
        }

        $userId = (int)$request->attributes->get('user')['user_id'];
        $amount = (float)$data['amount'];

        $providerConfig = $config['payment']['providers'][$provider] ?? null;

        if ($providerConfig === null) {
            return new JsonResponse([
                'success' => false,
                'errors' => ["Unknown payment provider: {$provider}"]
            ]);
        }

        $minAmount = (float)($providerConfig['minAmount'] ?? 0);
        $maxAmount = (float)($providerConfig['maxAmount'] ?? PHP_INT_MAX);

        if ($amount < $minAmount || $amount > $maxAmount) {
            return new JsonResponse([
                'success' => false,
                'errors' => ["Amount must be between {$minAmount} and {$maxAmount}"]
            ]);
        }

        $currency = $providerConfig['invoice']['currency'] ?? 'USD';
        $description = $providerConfig['invoice']['description'] ?? '';

        try {
            $payment = Payment::create($provider, $amount, $userId, $currency, $description);

            if (!$payment['success']) {
                return new JsonResponse([
                    'success' => false,
                    'errors' => [$payment['error'] ?? 'Failed to create invoice']
                ]);
            }

            /**
             * {"success":true,"order_id":"PAY-20260803-182017-17e4b603","invoice_url":"https:\/\/nowpayments.io\/payment\/?iid=4519710198","amount":10,"currency":"USD"}
             */
            return new JsonResponse([
                'success' => true,
                'order_id' => $payment['order_id'],
                'invoice_url' => $payment['invoice_url'],
                'amount' => $payment['amount'],
                'currency' => $payment['currency']
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'errors' => [$e->getMessage()]
            ]);
        }
    }

    public function list(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $userId = (int)$user['user_id'];

        if (!$userId) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['User ID is required']
            ], 400);
        }

        $payments = Payment::getPaymentsByUser($userId, 10, 0);

        $result = array_map(function($payment) {
            return [
                'order_id' => $payment['order_id'],
                'amount' => (float)$payment['amount'],
                'currency' => $payment['currency'],
                'status' => $payment['status'],
                'completed_at' => $payment['completed_at']
            ];
        }, $payments);

        return new JsonResponse([
            'success' => true,
            'data' => $result
        ]);
    }
}
