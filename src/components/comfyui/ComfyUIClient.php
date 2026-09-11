<?php

namespace Api\components\comfyui;

/**
 * Тонкий HTTP-клиент к ComfyUI (https://github.com/comfyanonymous/ComfyUI).
 * Без внешних зависимостей — обычный curl.
 */
class ComfyUIClient
{
    private string $domain;

    public function __construct(string $domain)
    {
        $this->domain = rtrim($domain, '/');
    }

    /**
     * POST /upload/image — кладёт файл в input/ ComfyUI.
     * Возвращает ['name' => ..., 'subfolder' => ..., 'type' => 'input'] —
     * $name (без subfolder, у нас его нет) — то, что подставляется в
     * LoadImage.inputs.image.
     *
     * @throws \RuntimeException
     */
    public function uploadImage(string $filePath, string $filename): array
    {
        $mime = mime_content_type($filePath) ?: 'application/octet-stream';
        $cfile = new \CURLFile($filePath, $mime, $filename);

        $response = $this->request('POST', '/upload/image', null, ['image' => $cfile], true);

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || empty($decoded['name'])) {
            throw new \RuntimeException('ComfyUI upload/image: unexpected response: ' . $response);
        }

        return $decoded;
    }

    /**
     * POST /prompt — ставит workflow в очередь. Возвращает prompt_id.
     *
     * @throws \RuntimeException
     */
    public function queuePrompt(array $workflow): string
    {
        $response = $this->request('POST', '/prompt', ['prompt' => $workflow]);

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || empty($decoded['prompt_id'])) {
            $errors = isset($decoded['node_errors']) ? json_encode($decoded['node_errors'], JSON_UNESCAPED_UNICODE) : $response;
            throw new \RuntimeException('ComfyUI /prompt rejected the workflow: ' . $errors);
        }

        return (string)$decoded['prompt_id'];
    }

    /**
     * GET /history/{promptId} — null, если ещё не появилось (в процессе),
     * иначе декодированная запись `{"prompt": [...], "outputs": {...}, "status": {...}}`.
     *
     * @throws \RuntimeException
     */
    public function getHistory(string $promptId): ?array
    {
        $response = $this->request('GET', '/history/' . rawurlencode($promptId));

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('ComfyUI /history: unexpected response: ' . $response);
        }

        if (!isset($decoded[$promptId])) {
            return null; // ещё в очереди/не завершилась
        }

        return $decoded[$promptId];
    }

    /**
     * GET /view — сырые байты изображения-результата.
     *
     * @throws \RuntimeException
     */
    public function viewImage(string $filename, string $subfolder, string $type): string
    {
        $query = http_build_query(['filename' => $filename, 'subfolder' => $subfolder, 'type' => $type]);

        return $this->request('GET', '/view?' . $query);
    }

    /**
     * @throws \RuntimeException
     */
    private function request(string $method, string $path, ?array $jsonBody = null, ?array $multipart = null, bool $isMultipart = false): string
    {
        $ch = curl_init($this->domain . $path);

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
        ];

        if ($isMultipart && $multipart !== null) {
            $options[CURLOPT_POSTFIELDS] = $multipart;
        } elseif ($jsonBody !== null) {
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
            $options[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException("ComfyUI request to {$path} failed: {$error}");
        }

        if ($status >= 400) {
            throw new \RuntimeException("ComfyUI request to {$path} returned HTTP {$status}: " . substr((string)$response, 0, 500));
        }

        return (string)$response;
    }
}
