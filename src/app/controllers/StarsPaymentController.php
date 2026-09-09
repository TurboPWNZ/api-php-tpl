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
 * Пополнение баланса звёздами Telegram (Bot API createInvoiceLink, currency=XTR).
 *
 * ⚠️ Сделано НЕЗАВИСИМО от src/components/payment/* (Payment/Provider/NOWPayments):
 * та обвязка ссылается на несуществующий класс Api\db\Account и вызывает на
 * Eloquent-моделях find()/update() со старой сигнатурой (raw where-строка + params),
 * которую Eloquent\Model не поддерживает — сейчас она нерабочая (упадёт с ошибкой
 * класса/метода, если её действительно дёрнуть). Пока это не почищено отдельно,
 * звёзды реализованы напрямую поверх Api\db\Payment (Eloquent) и
 * Api\db\TelegramAccount — по тому же паттерну, что уже работает в GameController.
 *
 * Ответный webhook (pre_checkout_query / successful_payment) — см. TelegramWebhookController.
 */
class StarsPaymentController
{
    const PROVIDER = 'TelegramStars';
    const CURRENCY = 'XTR';

    public function createInvoice(Request $request): JsonResponse
    {
        $telegramId = (int)($request->attributes->get('user')['user_id'] ?? 0);

        if (!$telegramId) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['Invalid user'],
            ], 401);
        }

        $data = $request->toArray();
        $amount = (int)($data['amount'] ?? 0);

        $config = Configurator::getConfig();
        $starsConfig = $config['payment']['providers'][self::PROVIDER] ?? [];
        $minAmount = (int)($starsConfig['minAmount'] ?? 1);
        $maxAmount = (int)($starsConfig['maxAmount'] ?? 100000);

        if ($amount < $minAmount || $amount > $maxAmount) {
            return new JsonResponse([
                'success' => false,
                'errors' => ["amount must be between {$minAmount} and {$maxAmount}"],
            ]);
        }

        $account = TelegramAccount::findByTelegramId($telegramId);
        if ($account === null) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['Account not found. Please re-login.'],
            ], 404);
        }

        $orderId = 'STARS-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $title = $starsConfig['invoice']['title'] ?? 'Top-up';
        $description = $starsConfig['invoice']['description'] ?? ('Пополнение баланса на ' . $amount . ' ★');

        // Currency XTR (Telegram Stars): provider_token пустой, ровно один LabeledPrice,
        // amount — целое число звёзд (у XTR нет дробных subunits).
        $botResponse = TelegramBotApi::call('createInvoiceLink', [
            'title' => $title,
            'description' => $description,
            'payload' => $orderId,
            'provider_token' => '',
            'currency' => self::CURRENCY,
            'prices' => [
                ['label' => $title, 'amount' => $amount],
            ],
        ]);

        if (empty($botResponse['ok'])) {
            Log::get(Log::PAYMENT)->error('Stars: createInvoiceLink failed', ['response' => $botResponse]);
            return new JsonResponse([
                'success' => false,
                'errors' => [$botResponse['description'] ?? 'Failed to create invoice'],
            ]);
        }

        $invoiceLink = $botResponse['result'];

        Payment::create([
            // Телеграм ID пользователя (как в JWT/GameController), а не внутренний PK
            // telegram_account.id — так проще сверять с TelegramAccount::findByTelegramId().
            'user_id' => $telegramId,
            'order_id' => $orderId,
            'provider' => self::PROVIDER,
            'amount' => $amount,
            'currency' => self::CURRENCY,
            'status' => Payment::STATUS_PENDING,
            'payload' => ['invoice_link' => $invoiceLink],
        ]);

        return new JsonResponse([
            'success' => true,
            'data' => [
                'invoiceLink' => $invoiceLink,
            ],
        ]);
    }
}
