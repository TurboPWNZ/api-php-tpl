<?php

namespace Api\components\payment;

use Api\db\Account;

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

    protected function getAccountBalance(int $userId): int
    {
        $account = (new Account())->findByPk($userId);
        return (int)($account['balance'] ?? 0);
    }

    public function updateAccountBalance(int $userId, int $amount): bool
    {
        $account = new Account();
        $currentBalance = $this->getAccountBalance($userId);
        return $account->update(
            'id = :id',
            [
                'id' => $userId,
                'balance' => $currentBalance + $amount
            ]
        ) > 0;
    }
}
