<?php

namespace Api\components\payment\providers;

use Api\components\Log;
use Api\components\payment\Provider;
use Api\components\TelegramBotApi;
use Api\Configurator;
use Api\db\Payment;

/**
 * Пополнение звёздами Telegram (Bot API, currency=XTR).
 *
 * Подключается через общий флоу компонента Payment:
 *   createInvoice()  — вызывается из Payment::createPayment() (POST /v1/payment/create-invoice/TelegramStars)
 *   verifyCallback() — вызывается из Payment::notification() (POST /v1/payment/notyfication/TelegramStars)
 *   getOrderState()  — оттуда же; для pre_checkout_query отвечает Bot API прямо здесь
 *                       (answerPreCheckoutQuery должен быть вызван в течение 10 секунд)
 *                       и возвращает orderId=null, чтобы Payment::notification() не
 *                       пытался менять статус заказа по этому апдейту.
 *
 * Настройка на стороне Telegram (один раз, вручную):
 *   POST https://api.telegram.org/bot<TOKEN>/setWebhook
 *     url=https://gamejw.duckdns.org/v1/payment/notyfication/TelegramStars
 *     secret_token=<payment.providers.TelegramStars.webhookSecret из конфига>
 *     allowed_updates=["pre_checkout_query","message"]
 */
class TelegramStars extends Provider
{
    protected string $name = 'TelegramStars';

    public function createInvoice(float $amount, string $currency, int $userId, string $orderId, string $description): array
    {
        $config = Configurator::getConfig();
        $title = $config['payment']['providers']['TelegramStars']['invoice']['title'] ?? 'Top-up';
        $stars = (int)round($amount); // у XTR нет дробных subunits

        $response = TelegramBotApi::call('createInvoiceLink', [
            'title' => $title,
            'description' => $description ?: $title,
            'payload' => $orderId,
            'provider_token' => '', // пусто для Stars (XTR) — провайдер не нужен
            'currency' => $currency,
            'prices' => [
                ['label' => $title, 'amount' => $stars],
            ],
        ]);

        if (empty($response['ok'])) {
            Log::get(Log::PAYMENT)->error('TelegramStars: createInvoiceLink failed', ['response' => $response]);
            return [
                'success' => false,
                'error' => $response['description'] ?? 'Failed to create invoice link',
            ];
        }

        return [
            'success' => true,
            'invoice_id' => $orderId, // у Stars нет отдельного provider invoice id — свой order_id и есть payload
            'invoice_url' => $response['result'],
        ];
    }

    public function verifyCallback(array $payload): bool
    {
        $config = Configurator::getConfig();
        $expected = $config['payment']['providers']['TelegramStars']['webhookSecret'] ?? '';
        $received = (string)($payload['receivedSecret'] ?? '');

        if ($expected === '' || $received === '') {
            return false;
        }

        return hash_equals($expected, $received);
    }

    /**
     * @return array{0:?string,1:float,2:string,3:string} [orderId, amount, message, status]
     */
    public function getOrderState(array $payload): array
    {
        $update = json_decode($payload['rawBody'] ?? '', true);

        if (!is_array($update)) {
            return [null, 0.0, 'invalid update payload', Payment::STATUS_PENDING];
        }

        if (isset($update['pre_checkout_query'])) {
            return $this->handlePreCheckoutQuery($update['pre_checkout_query']);
        }

        if (isset($update['message']['successful_payment'])) {
            $sp = $update['message']['successful_payment'];

            return [
                $sp['invoice_payload'] ?? null,
                (float)($sp['total_amount'] ?? 0),
                'telegram_payment_charge_id=' . ($sp['telegram_payment_charge_id'] ?? ''),
                Payment::STATUS_COMPLETED,
            ];
        }

        // Прочие типы апдейтов бота нас не интересуют.
        return [null, 0.0, 'ignored update', Payment::STATUS_PENDING];
    }

    private function handlePreCheckoutQuery(array $query): array
    {
        $orderId = $query['invoice_payload'] ?? null;
        $totalAmount = (float)($query['total_amount'] ?? 0);

        $payment = $orderId ? Payment::where('order_id', $orderId)->first() : null;

        // Подтверждаем, только если знаем такой заказ, он ещё не завершён и сумма
        // совпадает — простая защита от подмены/повторного использования payload.
        $ok = $payment !== null
            && $payment->status === Payment::STATUS_PENDING
            && (float)$payment->amount === $totalAmount;

        $params = [
            'pre_checkout_query_id' => $query['id'],
            'ok' => $ok,
        ];
        if (!$ok) {
            $params['error_message'] = 'Order not found or already processed';
        }

        $response = TelegramBotApi::call('answerPreCheckoutQuery', $params);

        Log::get(Log::PAYMENT)->info('TelegramStars: pre_checkout_query answered', [
            'order_id' => $orderId,
            'ok' => $ok,
            'bot_response' => $response,
        ]);

        // Это не смена статуса заказа (мы уже сами ответили Telegram выше) —
        // возвращаем orderId=null, чтобы Payment::notification() ничего не трогал.
        return [null, 0.0, 'pre_checkout_query answered ok=' . ($ok ? '1' : '0'), Payment::STATUS_PENDING];
    }
}
