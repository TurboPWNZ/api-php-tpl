<?php

namespace Api\db;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * ИИ-сервер (ComfyUI) из пула. Состояние меняет только воркер очереди
 * (Api\components\queue\GenerationQueue) под MySQL-локом.
 */
class AiServer extends Model
{
    const STATUS_FREE    = 'free';
    const STATUS_BUSY    = 'busy';
    const STATUS_OFFLINE = 'offline';

    protected $table = 'ai_servers';

    protected $fillable = [
        'env',
        'name',
        'url',
        'status',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'bool',
        'generation_id' => 'int',
        'fail_count' => 'int',
        'last_seen_at' => 'datetime',
        'checked_at' => 'datetime',
    ];

    public function markBusy(int $generationId): bool
    {
        return $this->forceFill([
            'status' => self::STATUS_BUSY,
            'generation_id' => $generationId,
        ])->save();
    }

    public function markFree(): bool
    {
        return $this->forceFill([
            'status' => self::STATUS_FREE,
            'generation_id' => null,
            'fail_count' => 0,
            'last_error' => null,
            'last_seen_at' => Carbon::now(),
        ])->save();
    }

    public function markOffline(string $error): bool
    {
        return $this->forceFill([
            'status' => self::STATUS_OFFLINE,
            'generation_id' => null,
            'last_error' => mb_substr($error, 0, 1000),
            'checked_at' => Carbon::now(),
        ])->save();
    }

    /** Сервер ответил — сбрасываем счётчик подряд идущих ошибок. */
    public function touchSeen(): bool
    {
        return $this->forceFill(['fail_count' => 0, 'last_seen_at' => Carbon::now()])->save();
    }

    /** @return int сколько ошибок подряд набралось */
    public function registerFailure(string $error): int
    {
        $this->forceFill([
            'fail_count' => $this->fail_count + 1,
            'last_error' => mb_substr($error, 0, 1000),
        ])->save();

        return $this->fail_count;
    }
}
