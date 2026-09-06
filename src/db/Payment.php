<?php

namespace Api\db;

class Payment extends AbstractTable
{
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    protected string $_table = 'payments';

    protected string $id = 'id';

    public function updatePaymentStatus(string $orderId, string $status): bool
    {
        return $this->update(
            'order_id = :orderId',
            [
                'order_id' => $orderId,
                'status' => $status
            ]
        ) > 0;
    }

    public function getPendingPayments(): array
    {
        return $this->findAll(
            'status = :status',
            ['status' => 'pending'],
            'created_at ASC'
        );
    }

    public function getPaymentsByUser(int $userId, int $limit = 10, int $offset = 0): array
    {
        $stmt = static::$pdo->prepare(
            "SELECT * FROM `{$this->_table}` WHERE user_id = :userId AND `status` = 'completed' 
                      ORDER BY created_at DESC LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':userId', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll();
    }
}
