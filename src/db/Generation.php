<?php

namespace Api\db;

use Illuminate\Database\Eloquent\Model;

class Generation extends Model
{
    const STATUS_PROCESSING = 'processing';
    const STATUS_READY      = 'ready';
    const STATUS_FAILED     = 'failed';

    protected $table = 'generations';

    protected $keyType = 'int';
    public $increments = true;

    protected $fillable = [
        'user_id',
        'status',
        'prompt',
        'cost',
        'source_path',
        'result_path',
        'comfy_prompt_id',
        'error',
    ];

    protected $casts = [
        'user_id' => 'int',
        'cost'    => 'float',
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
        ])->save();
    }

    public function markFailed(string $error): bool
    {
        return $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error' => $error,
        ])->save();
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
        ];
    }
}
