<?php

namespace Api\db;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    const STATUS_PENDING   = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';

    protected $table = 'payments';

    protected $keyType = 'int';
    public $increments = true;

    protected $fillable = [
        'user_id',
        'order_id',
        'provider',
        'amount',
        'currency',
        'status',
        'payload',
    ];

    protected $casts = [
        'amount'  => 'float',
        'payload' => 'array',
    ];

    protected $hidden = ['payload'];

    // ─── Scopes ─────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    // ─── Methods ────────────────────────────────────────────────────────────

    /**
     * Обновить статус оплаты по order_id
     */
    public static function updatePaymentStatus(string $orderId, string $status): bool
    {
        return static::where('order_id', $orderId)
            ->update(['status' => $status]) > 0;
    }

    /**
     * Получить все ожидающие оплаты (по возрастанию)
     */
    public static function getPendingPayments(): array
    {
        return static::pending()
            ->orderBy('created_at', 'ASC')
            ->get()
            ->toArray();
    }

    /**
     * Получить оплаты пользователя (только completed, по убыванию)
     */
    public static function getPaymentsByUser(int $userId, int $limit = 10, int $offset = 0): array
    {
        return static::forUser($userId)
            ->completed()
            ->orderBy('created_at', 'DESC')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->toArray();
    }
}
