<?php

namespace Api\components\payment;

use Api\db\TelegramAccount;

abstract class Provider
{
    protected string $name;
    protected string $apiKey;

    public function __construct()
    {
        $config = \Api\Configurator::load();
        $this->apiKey = $config['payment']['providers'][$this->name]['apiKey'] ?? '';
    }

    abstract public function createInvoice(float $amount, string $currency, int $userId, string $orderId, string $description): array;

    abstract public function verifyCallback(array $payload): bool;

    /**
     * Разбор состояния заказа из данных нотификации/вебхука провайдера.
     *
     * @return array{0:?string,1:float,2:string,3:string} [orderId, amount, message, status]
     *         (status — одна из Api\db\Payment::STATUS_*)
     */
    abstract public function getOrderState(array $payload): array;

    /**
     * `$telegramId` — это payments.user_id / JWT user_id, т.е. telegram_id
     * пользователя (не внутренний PK telegram_account.id) — так же, как
     * TelegramAccount::findByTelegramId() используется везде в GameController.
     */
    public function updateAccountBalance(int $telegramId, float $amount): bool
    {
        $account = TelegramAccount::findByTelegramId($telegramId);

        if ($account === null) {
            return false;
        }

        return $account->changeBalance($amount);
    }
}
