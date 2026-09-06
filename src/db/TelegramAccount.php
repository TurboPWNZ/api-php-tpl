<?php

namespace Api\db;

use Illuminate\Database\Eloquent\Model;

class TelegramAccount extends Model
{
    protected $table = 'telegram_account';

    protected $keyType = 'int';
    public $increments = true;

    protected $fillable = [
        'telegram_id',
        'username',
        'balance',
    ];

    protected $casts = [
        'telegram_id' => 'int',
        'balance'     => 'float',
    ];

    // ─── Scopes ─────────────────────────────────────────────────────────────

    public function scopeByTelegramId($query, int $telegramId)
    {
        return $query->where('telegram_id', $telegramId);
    }

    // ─── Methods ────────────────────────────────────────────────────────────

    /**
     * Получить аккаунт по telegram id
     */
    public static function findByTelegramId(int $telegramId): ?self
    {
        return static::byTelegramId($telegramId)->first();
    }

    /**
     * Найти или создать аккаунт по telegram id
     */
    public static function findOrCreateByTelegramId(int $telegramId, string $username, float $balance = 0): self
    {
        $account = static::findByTelegramId($telegramId);

        if ($account === null) {
            $account = static::create([
                'telegram_id' => $telegramId,
                'username'    => $username,
                'balance'     => $balance,
            ]);
        }

        return $account;
    }

    /**
     * Изменить баланс на заданное значение
     */
    public function setBalance(float $balance): bool
    {
        return $this->forceFill(['balance' => $balance])->save();
    }

    /**
     * Добавить к балансу (положительное или отрицательное значение)
     */
    public function changeBalance(float $delta): bool
    {
        $this->balance = round($this->balance + $delta, 2);

        return $this->save();
    }
}
