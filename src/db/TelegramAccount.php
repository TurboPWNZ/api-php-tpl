<?php

namespace Api\db;

use Illuminate\Database\Eloquent\Model;

class TelegramAccount extends Model
{
    protected $table = 'telegram_account';

    protected $keyType = 'int';
    public $increments = true;

    protected $fillable = [
        'username',
        'balance',
    ];

    protected $casts = [
        'balance' => 'float',
    ];

    // ─── Scopes ─────────────────────────────────────────────────────────────

    public function scopeByUsername($query, string $username)
    {
        return $query->where('username', $username);
    }

    // ─── Methods ────────────────────────────────────────────────────────────

    /**
     * Получить аккаунт по username
     */
    public static function findByUsername(string $username): ?self
    {
        return static::username($username)->first();
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
