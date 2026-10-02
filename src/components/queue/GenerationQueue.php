<?php

namespace Api\components\queue;

use Api\components\comfyui\ComfyUIClient;
use Api\components\comfyui\ComfyUIRejectedException;
use Api\components\comfyui\I2IWorkflow;
use Api\components\Log;
use Api\components\Storage;
use Api\Configurator;
use Api\db\AiServer;
use Api\db\DatabaseManager;
use Api\db\Generation;
use Api\db\Template;
use Carbon\Carbon;
use Monolog\Logger;

/**
 * Очередь генераций: раздаёт заказы (generations.status=queued) свободным
 * серверам из ai_servers и забирает результаты. Крутится в воркере
 * `php artisan queue:work` (systemd), один проход — tick().
 *
 * Работает только с серверами и заказами своего окружения (config
 * queue.env, колонка env): БД общая для докера и прода, а файлы фото — нет.
 *
 * Переходы состояний делает только tick() и только под MySQL GET_LOCK,
 * поэтому два воркера (или ручной queue:tick при живом воркере) не отдадут
 * один заказ двум серверам и не вернут кредиты дважды.
 */
class GenerationQueue
{
    private const LOCK_PREFIX = 'aigen_generation_queue_';

    private string $env;
    private int $jobTimeout;
    private int $maxAttempts;
    private int $healthCheckInterval;
    private int $serverFailThreshold;
    private Logger $log;

    public function __construct()
    {
        $queue = Configurator::getConfig()['queue'] ?? [];
        $this->jobTimeout = (int)($queue['jobTimeout'] ?? 600);
        $this->maxAttempts = (int)($queue['maxAttempts'] ?? 3);
        $this->healthCheckInterval = (int)($queue['healthCheckInterval'] ?? 30);
        $this->serverFailThreshold = (int)($queue['serverFailThreshold'] ?? 3);
        $this->log = Log::get('queue');
        $this->env = Configurator::queueEnv();
    }

    /**
     * Один проход. false — лок держит другой процесс, ничего не сделано.
     */
    public function tick(): bool
    {
        $db = DatabaseManager::connection();
        $lockName = self::LOCK_PREFIX . $this->env;
        if (!(int)$db->selectOne('SELECT GET_LOCK(?, 0) AS l', [$lockName])->l) {
            return false;
        }

        try {
            $this->reviveOfflineServers();
            $this->collectResults();
            $this->dispatchQueued();
        } finally {
            $db->selectOne('SELECT RELEASE_LOCK(?) AS l', [$lockName]);
        }

        return true;
    }

    /**
     * offline-серверы раз в healthCheckInterval проверяются через /queue и
     * возвращаются в пул, только когда их очередь ComfyUI пуста — сервер,
     * снятый по таймауту, может ещё дорабатывать старое задание.
     */
    private function reviveOfflineServers(): void
    {
        $threshold = Carbon::now()->subSeconds($this->healthCheckInterval);

        $servers = AiServer::where('env', $this->env)
            ->where('status', AiServer::STATUS_OFFLINE)
            ->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<=', $threshold))
            ->get();

        foreach ($servers as $server) {
            try {
                $queue = (new ComfyUIClient($server->url))->getQueue();
            } catch (\Throwable $e) {
                $server->forceFill(['checked_at' => Carbon::now(), 'last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
                continue;
            }

            if (empty($queue['queue_running']) && empty($queue['queue_pending'])) {
                $server->markFree();
                $this->log->info('Server back online', ['server_id' => $server->id]);
            } else {
                $server->forceFill(['checked_at' => Carbon::now()])->save();
            }
        }
    }

    private function collectResults(): void
    {
        foreach (AiServer::where('env', $this->env)->where('status', AiServer::STATUS_BUSY)->get() as $server) {
            $generation = $server->generation_id ? Generation::find($server->generation_id) : null;

            if ($generation === null
                || $generation->status !== Generation::STATUS_PROCESSING
                || (int)$generation->server_id !== (int)$server->id
            ) {
                $server->markFree();
                continue;
            }

            $client = new ComfyUIClient($server->url);

            try {
                $history = $client->getHistory((string)$generation->comfy_prompt_id);
            } catch (\Throwable $e) {
                $fails = $server->registerFailure($e->getMessage());
                $this->log->warning('Server poll failed', [
                    'server_id' => $server->id, 'generation_id' => $generation->id, 'fails' => $fails, 'error' => $e->getMessage(),
                ]);
                if ($fails >= $this->serverFailThreshold) {
                    $server->markOffline($e->getMessage());
                    $this->requeueOrFail($generation, 'AI server unreachable');
                }
                continue;
            }

            $server->touchSeen();

            if ($history === null) {
                $runningFor = time() - ($generation->started_at?->getTimestamp() ?? time());
                if ($runningFor > $this->jobTimeout) {
                    $this->log->warning('Generation timed out', [
                        'server_id' => $server->id, 'generation_id' => $generation->id, 'seconds' => $runningFor,
                    ]);
                    $server->markOffline("Generation {$generation->id} timed out after {$runningFor}s");
                    $this->requeueOrFail($generation, 'Generation timed out');
                }
                continue;
            }

            $this->finalize($generation, $client, $history);
            $server->markFree();
        }
    }

    private function finalize(Generation $generation, ComfyUIClient $client, array $history): void
    {
        $statusStr = $history['status']['status_str'] ?? null;
        $image = null;
        foreach ($history['outputs'] ?? [] as $nodeOutput) {
            if (!empty($nodeOutput['images'][0])) {
                $image = $nodeOutput['images'][0];
                break;
            }
        }

        if ($statusStr === 'error' || $image === null) {
            $this->fail($generation, 'ComfyUI: ' . ($statusStr ?? 'no output image'));
            return;
        }

        try {
            $bytes = $client->viewImage($image['filename'], $image['subfolder'] ?? '', $image['type'] ?? 'output');

            [$resultsAbsDir, $resultsRelDir] = Storage::userDir('results', (int)$generation->user_id);
            $resultFilename = Storage::randomFilename($image['filename'], 'png');
            file_put_contents("{$resultsAbsDir}/{$resultFilename}", $bytes);

            $generation->markReady("{$resultsRelDir}/{$resultFilename}");
            $this->log->info('Generation ready', ['generation_id' => $generation->id, 'server_id' => $generation->server_id]);
        } catch (\Throwable $e) {
            $this->fail($generation, 'Failed to fetch result: ' . $e->getMessage());
        }
    }

    /**
     * Свободным серверам — самые старые заказы (FIFO). Сервер, который не
     * принял заказ из-за сетевой ошибки/5xx, уходит в offline, и заказ
     * пробует следующий; 4xx (заказ отвергнут) — сервер остаётся в пуле.
     * attempts считает только реальные отправки и отказы по самому заказу.
     */
    private function dispatchQueued(): void
    {
        while (true) {
            // Дольше всех не получавший работу (updated_at) — равномерная загрузка.
            $server = AiServer::where('env', $this->env)
                ->where('status', AiServer::STATUS_FREE)
                ->where('enabled', true)
                ->orderBy('updated_at')
                ->first();
            if ($server === null) {
                return;
            }

            $generation = Generation::where('env', $this->env)
                ->where('status', Generation::STATUS_QUEUED)
                ->orderBy('id')
                ->first();
            if ($generation === null) {
                return;
            }

            $sourceAbsPath = Storage::absolutePath($generation->source_path);
            if (!is_file($sourceAbsPath)) {
                $this->fail($generation, 'Source image is missing');
                continue;
            }

            try {
                $promptId = $this->submit($server, $generation, $sourceAbsPath);
            } catch (ComfyUIRejectedException $e) {
                // Сервер жив, но отверг именно этот заказ — тратим попытку заказа.
                $this->log->warning('Dispatch rejected', [
                    'server_id' => $server->id, 'generation_id' => $generation->id, 'error' => $e->getMessage(),
                ]);
                $server->touchSeen();
                $generation->forceFill(['attempts' => $generation->attempts + 1])->save();
                $this->requeueOrFail($generation, 'Generation service is unavailable');
                continue;
            } catch (\Throwable $e) {
                // Сервер недоступен — не вина заказа: попытку не тратим, заказ
                // остаётся в очереди, сервер — в offline до health-check.
                $this->log->warning('Dispatch failed, server offline', [
                    'server_id' => $server->id, 'generation_id' => $generation->id, 'error' => $e->getMessage(),
                ]);
                $server->markOffline($e->getMessage());
                continue;
            }

            $generation->forceFill([
                'attempts' => $generation->attempts + 1,
                'status' => Generation::STATUS_PROCESSING,
                'server_id' => $server->id,
                'comfy_prompt_id' => $promptId,
                'started_at' => Carbon::now(),
            ])->save();
            $server->markBusy($generation->id);

            $this->log->info('Generation dispatched', [
                'generation_id' => $generation->id, 'server_id' => $server->id, 'attempt' => $generation->attempts,
            ]);
        }
    }

    private function submit(AiServer $server, Generation $generation, string $sourceAbsPath): string
    {
        $config = Configurator::getConfig();
        $workflowPath = Configurator::projectRoot() . '/' . ltrim((string)($config['comfyui']['i2i']['workflow'] ?? ''), '/');

        // workflow шаблона заказа; заказы до шаблонов (template_id = null) —
        // прежний файл из конфига.
        $templateWorkflow = $generation->template_id
            ? Template::query()->where('id', $generation->template_id)->value('workflow')
            : null;

        $client = new ComfyUIClient($server->url);
        $uploaded = $client->uploadImage($sourceAbsPath, basename($sourceAbsPath));
        $prompt = $this->fullPrompt($generation, $config);
        $workflow = $templateWorkflow !== null
            ? I2IWorkflow::buildFromJson($templateWorkflow, $uploaded['name'], $prompt)
            : I2IWorkflow::build($workflowPath, $uploaded['name'], $prompt);

        return $client->queuePrompt($workflow);
    }

    /**
     * full_prompt считается при заказе; у заказов, созданных до очереди
     * (перезапущенных миграцией), его нет — собираем из конфига как раньше.
     */
    private function fullPrompt(Generation $generation, array $config): string
    {
        if ($generation->full_prompt !== null) {
            return (string)$generation->full_prompt;
        }

        $systemPrompt = (string)($config['comfyui']['i2i']['systemPromt'] ?? '');

        return $generation->prompt ? "{$systemPrompt}, {$generation->prompt}" : $systemPrompt;
    }

    /** Обратно в очередь (в её начало — FIFO по id), если попытки не кончились. */
    private function requeueOrFail(Generation $generation, string $reason): void
    {
        if ($generation->attempts >= $this->maxAttempts) {
            $this->fail($generation, $reason);
            return;
        }

        $generation->forceFill([
            'status' => Generation::STATUS_QUEUED,
            'server_id' => null,
            'comfy_prompt_id' => null,
            'started_at' => null,
        ])->save();

        $this->log->info('Generation requeued', ['generation_id' => $generation->id, 'reason' => $reason]);
    }

    private function fail(Generation $generation, string $error): void
    {
        $generation->markFailed($error);
        $generation->refund();

        $this->log->warning('Generation failed, refunded', [
            'generation_id' => $generation->id, 'error' => $error, 'cost' => $generation->cost,
        ]);
    }
}
