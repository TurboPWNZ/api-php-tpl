<?php

namespace Api\db;

use Illuminate\Database\Eloquent\Model;

class TelegramAccount extends Model
{
    // 'guest' — ни разу не пополнял баланс (пока хватает стартового/бонусного);
    // 'customer' — хотя бы одно пополнение прошло успешно. Определяет, какой
    // systemPromt(Guest) подставляется в генерации — см. GenerationController.
    public const STATUS_GUEST = 'guest';
    public const STATUS_CUSTOMER = 'customer';

    protected $table = 'telegram_account';

    protected $keyType = 'int';
    public $increments = true;

    protected $fillable = [
        'telegram_id',
        'username',
        'balance',
        'status',
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

    public function isGuest(): bool
    {
        return $this->status !== self::STATUS_CUSTOMER;
    }

    /**
     * Помечает аккаунт как платящего клиента — вызывается один раз, в
     * момент первого успешного пополнения (Provider::updateAccountBalance).
     * Статус не понижается обратно, даже если баланс потом снова уйдёт в 0.
     */
    public function markAsCustomer(): bool
    {
        if ($this->status === self::STATUS_CUSTOMER) {
            return true;
        }

        $this->status = self::STATUS_CUSTOMER;

        return $this->save();
    }
}
