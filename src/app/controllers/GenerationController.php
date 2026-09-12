<?php

namespace Api\app\controllers;

use Api\components\comfyui\ComfyUIClient;
use Api\components\comfyui\I2IWorkflow;
use Api\components\Log;
use Api\components\Storage;
use Api\Configurator;
use Api\db\DatabaseManager;
use Api\db\Generation;
use Api\db\TelegramAccount;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class GenerationController
{
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_SIZE_BYTES = 20 * 1024 * 1024; // 20 МБ — как обещано на фронте

    /**
     * POST /v1/generation/create — multipart/form-data: image (файл) + prompt (опционально).
     * Списывает стоимость, кладёт фото в очередь ComfyUI, создаёт запись
     * generations со status=processing. Результат забирается через
     * GET /v1/generation/status/{id} (ComfyUI генерирует не мгновенно).
     */
    public function create(Request $request): JsonResponse
    {
        $userId = (int)($request->attributes->get('user')['user_id'] ?? 0);
        if (!$userId) {
            return new JsonResponse(['success' => false, 'errors' => ['Invalid user']], 401);
        }

        $file = $request->files->get('image');
        if ($file === null || !$file->isValid()) {
            return new JsonResponse(['success' => false, 'errors' => ['image file is required']], 400);
        }
        // Не $file->getMimeType() — тот требует symfony/mime, которого нет
        // в зависимостях; fileinfo (ядро PHP) и так уже нужен ComfyUIClient.
        $mimeType = mime_content_type($file->getPathname()) ?: '';
        if (!in_array($mimeType, self::ALLOWED_MIME, true)) {
            return new JsonResponse(['success' => false, 'errors' => ['image must be JPEG, PNG or WebP']], 400);
        }
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            return new JsonResponse(['success' => false, 'errors' => ['image must be up to 20 MB']], 400);
        }

        $userPrompt = trim((string)$request->request->get('prompt', ''));

        $config = Configurator::getConfig();
        $cost = $userPrompt !== ''
            ? (float)($config['generate']['costWithPrompt'] ?? 8)
            : (float)($config['generate']['costBase'] ?? 4);

        $account = TelegramAccount::findByTelegramId($userId);
        if ($account === null) {
            return new JsonResponse(['success' => false, 'errors' => ['Account not found. Please re-login.']], 404);
        }

        // Атомарно: спишет, только если баланса хватает (см. комментарий в
        // DatabaseManager про MYSQL_ATTR_FOUND_ROWS — на нём и построена
        // эта проверка через affected rows).
        $deducted = DatabaseManager::connection()
            ->table('telegram_account')
            ->where('id', $account->id)
            ->where('balance', '>=', $cost)
            ->decrement('balance', $cost);

        if ($deducted === 0) {
            return new JsonResponse(['success' => false, 'errors' => ['Insufficient balance']], 400);
        }

        try {
            // 1. Сохраняем оригинал на диск (у нас — навсегда, для "Было").
            [$uploadsAbsDir, $uploadsRelDir] = Storage::userDir('uploads', $userId);
            $filename = Storage::randomFilename($file->getClientOriginalName());
            $file->move($uploadsAbsDir, $filename);
            $sourceRelPath = "{$uploadsRelDir}/{$filename}";
            $sourceAbsPath = "{$uploadsAbsDir}/{$filename}";

            // 2. Грузим ту же картинку в ComfyUI (у него свой input/) и
            //    патчим workflow: картинка + промпт (systemPromt[, userPrompt]).
            //    Гостю (ни разу не пополнял баланс) — systemPromtGuest, платящему
            //    клиенту — обычный systemPromt (см. TelegramAccount::isGuest()).
            $comfyDomain = (string)($config['comfyui']['domain'] ?? '');
            $workflowPath = Configurator::projectRoot() . '/' . ltrim((string)($config['comfyui']['i2i']['workflow'] ?? ''), '/');
            $systemPromptKey = $account->isGuest() ? 'systemPromtGuest' : 'systemPromt';
            $systemPrompt = (string)($config['comfyui']['i2i'][$systemPromptKey] ?? $config['comfyui']['i2i']['systemPromt'] ?? '');
            $prompt = $userPrompt !== '' ? "{$systemPrompt}, {$userPrompt}" : $systemPrompt;

            $client = new ComfyUIClient($comfyDomain);
            $uploaded = $client->uploadImage($sourceAbsPath, $filename);
            $workflow = I2IWorkflow::build($workflowPath, $uploaded['name'], $prompt);
            $promptId = $client->queuePrompt($workflow);
        } catch (\Throwable $e) {
            // Не смогли поставить генерацию в очередь — возвращаем списанное.
            DatabaseManager::connection()->table('telegram_account')
                ->where('id', $account->id)
                ->increment('balance', $cost);

            Log::get(Log::DEBUG)->error('Generation create failed', [
                'user_id' => $userId, 'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['success' => false, 'errors' => ['Generation service is unavailable']], 502);
        }

        $generation = Generation::create([
            'user_id' => $userId,
            'status' => Generation::STATUS_PROCESSING,
            'prompt' => $userPrompt !== '' ? $userPrompt : null,
            'cost' => $cost,
            'source_path' => $sourceRelPath,
            'comfy_prompt_id' => $promptId,
        ]);

        Log::get(Log::DEBUG)->info('Generation queued', [
            'user_id' => $userId, 'generation_id' => $generation->id, 'comfy_prompt_id' => $promptId, 'cost' => $cost,
        ]);

        return new JsonResponse([
            'success' => true,
            'data' => $generation->toApiArray($request->getSchemeAndHttpHost()),
        ]);
    }

    /**
     * GET /v1/generation/status/{id} — опрашивается фронтом, пока status=processing.
     */
    public function status(Request $request, string $id): JsonResponse
    {
        $userId = (int)($request->attributes->get('user')['user_id'] ?? 0);

        $generation = Generation::find((int)$id);
        if ($generation === null || (int)$generation->user_id !== $userId) {
            return new JsonResponse(['success' => false, 'errors' => ['Generation not found']], 404);
        }

        if ($generation->status !== Generation::STATUS_PROCESSING) {
            return new JsonResponse(['success' => true, 'data' => $generation->toApiArray($request->getSchemeAndHttpHost())]);
        }

        $config = Configurator::getConfig();
        $client = new ComfyUIClient((string)($config['comfyui']['domain'] ?? ''));

        try {
            $history = $client->getHistory($generation->comfy_prompt_id);
        } catch (\Throwable $e) {
            Log::get(Log::DEBUG)->error('Generation status check failed', [
                'generation_id' => $generation->id, 'error' => $e->getMessage(),
            ]);
            return new JsonResponse(['success' => true, 'data' => $generation->toApiArray($request->getSchemeAndHttpHost())]);
        }

        if ($history === null) {
            // Ещё в очереди/выполняется в ComfyUI.
            return new JsonResponse(['success' => true, 'data' => $generation->toApiArray($request->getSchemeAndHttpHost())]);
        }

        $statusStr = $history['status']['status_str'] ?? null;
        $image = null;
        foreach ($history['outputs'] ?? [] as $nodeOutput) {
            if (!empty($nodeOutput['images'][0])) {
                $image = $nodeOutput['images'][0];
                break;
            }
        }

        if ($statusStr === 'error' || $image === null) {
            $this->refund($generation);
            $generation->markFailed('ComfyUI: ' . ($statusStr ?? 'no output image'));

            return new JsonResponse(['success' => true, 'data' => $generation->toApiArray($request->getSchemeAndHttpHost())]);
        }

        try {
            $bytes = $client->viewImage($image['filename'], $image['subfolder'] ?? '', $image['type'] ?? 'output');

            [$resultsAbsDir, $resultsRelDir] = Storage::userDir('results', $userId);
            $resultFilename = Storage::randomFilename($image['filename'], 'png');
            file_put_contents("{$resultsAbsDir}/{$resultFilename}", $bytes);

            $generation->markReady("{$resultsRelDir}/{$resultFilename}");
        } catch (\Throwable $e) {
            Log::get(Log::DEBUG)->error('Generation result fetch failed', [
                'generation_id' => $generation->id, 'error' => $e->getMessage(),
            ]);
            $this->refund($generation);
            $generation->markFailed('Failed to fetch result: ' . $e->getMessage());
        }

        return new JsonResponse(['success' => true, 'data' => $generation->toApiArray($request->getSchemeAndHttpHost())]);
    }

    /**
     * GET /v1/generation/list — история генераций текущего пользователя, "Было — Стало".
     */
    public function list(Request $request): JsonResponse
    {
        $userId = (int)($request->attributes->get('user')['user_id'] ?? 0);

        $limit = max(1, min(100, (int)($request->query->get('limit', 20))));
        $offset = max(0, (int)($request->query->get('offset', 0)));

        $baseUrl = $request->getSchemeAndHttpHost();
        $items = Generation::getByUser($userId, $limit, $offset)
            ->map(fn (Generation $g) => $g->toApiArray($baseUrl))
            ->values()
            ->all();

        return new JsonResponse(['success' => true, 'data' => $items]);
    }

    private function refund(Generation $generation): void
    {
        // generations.user_id хранит telegram_id (как payments.user_id) —
        // а в самой telegram_account эта колонка называется telegram_id.
        DatabaseManager::connection()->table('telegram_account')
            ->where('telegram_id', $generation->user_id)
            ->increment('balance', $generation->cost);
    }
}
