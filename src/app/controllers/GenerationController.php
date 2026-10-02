<?php

namespace Api\app\controllers;

use Api\components\Log;
use Api\components\Storage;
use Api\Configurator;
use Api\db\DatabaseManager;
use Api\db\Generation;
use Api\db\Template;
use Api\db\TelegramAccount;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class GenerationController
{
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_SIZE_BYTES = 20 * 1024 * 1024; // 20 МБ — как обещано на фронте

    /**
     * POST /v1/generation/create — multipart/form-data: image (файл), prompt и
     * template_id (опционально; без него — шаблон по умолчанию, первый доступный).
     * Списывает стоимость, сохраняет фото и ставит заказ в очередь
     * (generations.status=queued). Воркер очереди отдаёт его свободному
     * ИИ-серверу; результат фронт ждёт через GET /v1/generation/status/{id}.
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

        $templateId = (int)$request->request->get('template_id', 0);
        $template = $templateId
            ? Template::withoutBlobs()->where('enabled', true)->find($templateId)
            : Template::defaultTemplate();
        if ($templateId && $template === null) {
            return new JsonResponse(['success' => false, 'errors' => ['Template not found']], 400);
        }

        // Ни одного шаблона ещё нет (или клиент без template_id при пустой
        // таблице) — прежнее поведение: цены и промпты из конфига, workflow
        // из comfyui.i2i.workflow (GenerationQueue при template_id = null).
        $config = Configurator::getConfig();
        $cost = $template !== null
            ? $template->costFor($userPrompt !== '')
            : (float)($userPrompt !== '' ? ($config['generate']['costWithPrompt'] ?? 8) : ($config['generate']['costBase'] ?? 4));

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
            // Оригинал на диск (у нас — навсегда, для "Было"). В ComfyUI его
            // отправит воркер очереди, когда освободится сервер.
            [$uploadsAbsDir, $uploadsRelDir] = Storage::userDir('uploads', $userId);
            $filename = Storage::randomFilename($file->getClientOriginalName());
            $file->move($uploadsAbsDir, $filename);
        } catch (\Throwable $e) {
            DatabaseManager::connection()->table('telegram_account')
                ->where('id', $account->id)
                ->increment('balance', $cost);

            Log::get(Log::DEBUG)->error('Generation upload failed', [
                'user_id' => $userId, 'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['success' => false, 'errors' => ['Generation service is unavailable']], 502);
        }

        // Гостю (ни разу не пополнял баланс) — prompt_guest шаблона, платящему
        // клиенту — prompt (см. TelegramAccount::isGuest()). Считается сейчас,
        // на момент заказа, и хранится в full_prompt.
        $systemPrompt = $template !== null
            ? $template->systemPromptFor($account->isGuest())
            : (string)($config['comfyui']['i2i'][$account->isGuest() ? 'systemPromtGuest' : 'systemPromt'] ?? $config['comfyui']['i2i']['systemPromt'] ?? '');

        $generation = Generation::create([
            'user_id' => $userId,
            'env' => Configurator::queueEnv(),
            'template_id' => $template?->id,
            'status' => Generation::STATUS_QUEUED,
            'prompt' => $userPrompt !== '' ? $userPrompt : null,
            'full_prompt' => $userPrompt !== '' ? "{$systemPrompt}, {$userPrompt}" : $systemPrompt,
            'cost' => $cost,
            'source_path' => "{$uploadsRelDir}/{$filename}",
        ]);

        Log::get(Log::DEBUG)->info('Generation queued', [
            'user_id' => $userId, 'generation_id' => $generation->id, 'template_id' => $template?->id, 'cost' => $cost,
        ]);

        return new JsonResponse([
            'success' => true,
            'data' => $generation->toApiArray($request->getSchemeAndHttpHost()),
        ]);
    }

    /**
     * GET /v1/generation/status/{id} — опрашивается фронтом, пока заказ
     * queued/processing. Только читает БД: раздачу по серверам и сбор
     * результатов делает воркер очереди (GenerationQueue).
     */
    public function status(Request $request, string $id): JsonResponse
    {
        $userId = (int)($request->attributes->get('user')['user_id'] ?? 0);

        $generation = Generation::find((int)$id);
        if ($generation === null || (int)$generation->user_id !== $userId) {
            return new JsonResponse(['success' => false, 'errors' => ['Generation not found']], 404);
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

    /**
     * DELETE /v1/generation/{id} — пользователь удаляет свою генерацию.
     * Запись помечается deleted_at (история списаний сохраняется), файлы
     * оригинала и результата удаляются с диска. Генерацию в очереди/в процессе
     * удалять нельзя: с ней ещё работает воркер очереди (refund/markReady).
     */
    public function delete(Request $request, string $id): JsonResponse
    {
        $userId = (int)($request->attributes->get('user')['user_id'] ?? 0);

        $generation = Generation::find((int)$id);
        if ($generation === null || (int)$generation->user_id !== $userId) {
            return new JsonResponse(['success' => false, 'errors' => ['Generation not found']], 404);
        }

        if ($generation->isActive()) {
            return new JsonResponse(['success' => false, 'errors' => ['Generation is in progress']], 409);
        }

        $generation->delete();

        foreach (array_filter([$generation->source_path, $generation->result_path]) as $path) {
            if (!Storage::deleteFile($path)) {
                Log::get(Log::DEBUG)->warning('Generation file delete failed', [
                    'generation_id' => $generation->id, 'path' => $path,
                ]);
            }
        }

        Log::get(Log::DEBUG)->info('Generation deleted', [
            'user_id' => $userId, 'generation_id' => $generation->id,
        ]);

        return new JsonResponse(['success' => true]);
    }
}
