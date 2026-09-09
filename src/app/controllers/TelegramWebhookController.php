<?php

namespace Api\app\controllers;

use Api\components\Log;
use Api\components\TelegramBotApi;
use Api\Configurator;
use Api\db\Payment;
use Api\db\TelegramAccount;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Вебхук Telegram Bot API. Сейчас обрабатывает только апдейты, связанные
 * с оплатой звёздами (см. StarsPaymentController::createInvoice):
 * pre_checkout_query и message.successful_payment. Любые другие типы
 * апдейтов бота (команды и т.п.) здесь не реализованы — просто игнорируются.
 *
 * Настройка на стороне Telegram (один раз, вручную):
 *   POST https://api.telegram.org/bot<TOKEN>/setWebhook
 *     url=https://gamejw.duckdns.org/telegram/webhook
 *     secret_token=<payment.providers.TelegramStars.webhookSecret из конфига>
 *     allowed_updates=["pre_checkout_query","message"]
 *
 * Аутентификация вебхука — не JWT (это не наш пользователь, а сам Telegram),
 * а секрет из setWebhook, приходящий в заголовке X-Telegram-Bot-Api-Secret-Token.
 */
class TelegramWebhookController
{
    public function handle(Request $request): JsonResponse
    {
        $config = Configurator::getConfig();
        $expectedSecret = $config['payment']['providers']['TelegramStars']['webhookSecret'] ?? '';
        $receivedSecret = (string)$request->headers->get('X-Telegram-Bot-Api-Secret-Token', '');

        if ($expectedSecret === '' || !hash_equals($expectedSecret, $receivedSecret)) {
            Log::get(Log::PAYMENT)->warning('Stars webhook: invalid or missing secret token');
            return new JsonResponse(['success' => false], 401);
        }

        $update = json_decode($request->getContent(), true);

        if (!is_array($update)) {
            return new JsonResponse(['success' => false], 400);
        }

        Log::get(Log::PAYMENT)->info('Stars webhook: update received', ['update' => $update]);

        if (isset($update['pre_checkout_query'])) {
            $this->handlePreCheckoutQuery($update['pre_checkout_query']);
        } elseif (isset($update['message']['successful_payment'])) {
            $this->handleSuccessfulPayment($update['message']['successful_payment']);
        }
        // Прочие апдейты нас не интересуют — просто подтверждаем получение (200),
        // иначе Telegram будет повторять доставку.

        return new JsonResponse(['success' => true]);
    }

    private function handlePreCheckoutQuery(array $query): void
    {
        $orderId = (string)($query['invoice_payload'] ?? '');
        $totalAmount = (int)($query['total_amount'] ?? 0);

        $payment = Payment::where('order_id', $orderId)->first();

        // Подтверждаем, только если знаем такой заказ, он ещё не завершён и сумма совпадает —
        // простая защита от подмены/повторного использования payload.
        $ok = $payment !== null
            && $payment->provider === 'TelegramStars'
            && $payment->status === Payment::STATUS_PENDING
            && (int)$payment->amount === $totalAmount;

        $params = [
            'pre_checkout_query_id' => $query['id'],
            'ok' => $ok,
        ];
        if (!$ok) {
            $params['error_message'] = 'Заказ не найден или уже обработан';
        }

        $botResponse = TelegramBotApi::call('answerPreCheckoutQuery', $params);

        Log::get(Log::PAYMENT)->info('Stars webhook: pre_checkout_query answered', [
            'order_id' => $orderId,
            'ok' => $ok,
            'bot_response' => $botResponse,
        ]);
    }

    private function handleSuccessfulPayment(array $successfulPayment): void
    {
        $orderId = (string)($successfulPayment['invoice_payload'] ?? '');
        $totalAmount = (int)($successfulPayment['total_amount'] ?? 0);
        $chargeId = (string)($successfulPayment['telegram_payment_charge_id'] ?? '');

        $payment = Payment::where('order_id', $orderId)->first();

        if ($payment === null) {
            Log::get(Log::PAYMENT)->error('Stars webhook: successful_payment for unknown order', ['order_id' => $orderId]);
            return;
        }

        // Атомарный "захват" платежа: если Telegram продублирует доставку
        // (например, из-за таймаута предыдущего ответа), второй раз affected=0
        // и звёзды повторно не начислятся.
        $payloadJson = json_encode(
            array_merge($payment->payload ?? [], ['telegram_payment_charge_id' => $chargeId]),
            JSON_UNESCAPED_UNICODE
        );

        $claimed = Payment::where('id', $payment->id)
            ->where('status', Payment::STATUS_PENDING)
            ->update([
                'status' => Payment::STATUS_COMPLETED,
                'payload' => $payloadJson,
            ]);

        if ($claimed === 0) {
            Log::get(Log::PAYMENT)->info('Stars webhook: payment already completed, skipping', ['order_id' => $orderId]);
            return;
        }

        $account = TelegramAccount::findByTelegramId((int)$payment->user_id);

        if ($account === null) {
            Log::get(Log::PAYMENT)->error('Stars webhook: account not found after claiming payment', [
                'order_id' => $orderId,
                'user_id' => $payment->user_id,
            ]);
            return;
        }

        $account->changeBalance($totalAmount);

        Log::get(Log::PAYMENT)->info('Stars webhook: payment completed, balance credited', [
            'order_id' => $orderId,
            'user_id' => $payment->user_id,
            'amount' => $totalAmount,
            'charge_id' => $chargeId,
            'new_balance' => $account->balance,
        ]);
    }
}
