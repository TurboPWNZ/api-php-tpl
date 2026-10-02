<?php

namespace Api\db;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Generation extends Model
{
    // Удалённые пользователем генерации скрыты из всех запросов (list,
    // status, find) — глобальный скоуп SoftDeletes.
    use SoftDeletes;

    const STATUS_QUEUED     = 'queued';     // ждёт свободный ИИ-сервер
    const STATUS_PROCESSING = 'processing'; // считается на server_id
    const STATUS_READY      = 'ready';
    const STATUS_FAILED     = 'failed';

    protected $table = 'generations';

    protected $keyType = 'int';
    public $increments = true;

    protected $fillable = [
        'user_id',
        'env',
        'status',
        'prompt',
        'full_prompt',
        'cost',
        'source_path',
        'result_path',
        'comfy_prompt_id',
        'error',
    ];

    protected $casts = [
        'user_id'     => 'int',
        'cost'        => 'float',
        'server_id'   => 'int',
        'attempts'    => 'int',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    // ─── Scopes ─────────────────────────────────────────────────────────────

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    // ─── Methods ────────────────────────────────────────────────────────────

    public function markReady(string $resultPath): bool
    {
        return $this->forceFill([
            'status' => self::STATUS_READY,
            'result_path' => $resultPath,
            'finished_at' => \Carbon\Carbon::now(),
        ])->save();
    }

    public function markFailed(string $error): bool
    {
        return $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error' => $error,
            'finished_at' => \Carbon\Carbon::now(),
        ])->save();
    }

    /** Генерация ещё не завершена — удалять нельзя, воркер с ней работает. */
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_PROCESSING], true);
    }

    /**
     * Возврат списанного при неудаче. Вызывающий отвечает за то, чтобы это
     * случилось один раз — воркер делает это вместе с markFailed под локом.
     */
    public function refund(): void
    {
        // generations.user_id хранит telegram_id (как payments.user_id) —
        // а в самой telegram_account эта колонка называется telegram_id.
        \Api\db\DatabaseManager::connection()->table('telegram_account')
            ->where('telegram_id', $this->user_id)
            ->increment('balance', $this->cost);
    }

    /** 1-based место в очереди, null — если генерация не в очереди. */
    public function queuePosition(): ?int
    {
        if ($this->status !== self::STATUS_QUEUED) {
            return null;
        }

        return static::where('env', $this->env)
            ->where('status', self::STATUS_QUEUED)
            ->where('id', '<=', $this->id)
            ->count();
    }

    /**
     * Список генераций пользователя (новые сверху). Возвращает коллекцию
     * моделей (не ->toArray()) — контроллер вызывает toApiArray() на каждой.
     */
    public static function getByUser(int $userId, int $limit = 20, int $offset = 0): \Illuminate\Support\Collection
    {
        return static::forUser($userId)
            ->orderBy('created_at', 'DESC')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    /**
     * Представление для фронта: пути превращаются в абсолютные URL
     * (`$baseUrl` — обычно $request->getSchemeAndHttpHost()), дата отдаётся
     * как ISO-строка — форматирование под локаль делает фронт.
     */
    public function toApiArray(string $baseUrl): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'prompt' => $this->prompt,
            'cost' => (float)$this->cost,
            'createdAt' => $this->created_at?->toIso8601String(),
            'sourceUrl' => $baseUrl . '/storage/' . ltrim($this->source_path, '/'),
            'resultUrl' => $this->result_path ? $baseUrl . '/storage/' . ltrim($this->result_path, '/') : null,
            'error' => $this->status === self::STATUS_FAILED ? $this->error : null,
            'queuePosition' => $this->queuePosition(),
        ];
    }
}
