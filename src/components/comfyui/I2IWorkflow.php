<?php

namespace Api\components\comfyui;

/**
 * Патчит шаблон workflow'а image-to-image (comfy/workflow/i2i_host_gen.json)
 * под конкретный запрос: подставляет загруженную в ComfyUI картинку в узел
 * LoadImage и текст промпта в CLIPTextEncode (через плейсхолдер
 * "{{user_promt}}", уже вписанный в шаблон).
 *
 * Завязано на структуру конкретно этого шаблона (один LoadImage, один
 * RandomNoise) — при замене workflow'а на другой эти узлы нужно проверить
 * заново.
 */
class I2IWorkflow
{
    /**
     * @param string $templatePath Абсолютный путь к JSON-шаблону
     * @param string $imageFilename Имя файла, под которым картинка загружена
     *                              в ComfyUI (ComfyUIClient::uploadImage()['name'])
     * @param string $prompt Готовый текст промпта (systemPromt [+ ", " + пользовательский])
     *
     * @return array Патченый граф — то, что уходит в ComfyUIClient::queuePrompt()
     *
     * @throws \RuntimeException
     */
    public static function build(string $templatePath, string $imageFilename, string $prompt): array
    {
        $raw = file_get_contents($templatePath);
        if ($raw === false) {
            throw new \RuntimeException("Workflow template not found: {$templatePath}");
        }

        return self::buildFromJson($raw, $imageFilename, $prompt);
    }

    /**
     * То же, что build(), но workflow уже строкой (templates.workflow в БД).
     *
     * @throws \RuntimeException
     */
    public static function buildFromJson(string $raw, string $imageFilename, string $prompt): array
    {
        // Промпт подставляется внутрь JSON-строки — экранируем как JSON
        // (без внешних кавычек), иначе кавычка/перевод строки в описании
        // пользователя ломают весь workflow.
        $raw = str_replace('{{user_promt}}', substr(json_encode($prompt, JSON_UNESCAPED_UNICODE), 1, -1), $raw);

        $graph = json_decode($raw, true);
        if (!is_array($graph)) {
            throw new \RuntimeException('Workflow template is not valid JSON');
        }

        $hasLoadImage = false;
        foreach ($graph as &$node) {
            $classType = $node['class_type'] ?? null;

            if ($classType === 'LoadImage') {
                $node['inputs']['image'] = $imageFilename;
                $hasLoadImage = true;
            }

            if ($classType === 'RandomNoise') {
                // Шаблон фиксирует noise_seed=0 — с ним каждая генерация
                // с одинаковым фото и промптом давала бы одну и ту же
                // картинку. Рандомизируем на каждый запрос.
                $node['inputs']['noise_seed'] = random_int(0, 2 ** 31 - 1);
            }
        }
        unset($node);

        if (!$hasLoadImage) {
            throw new \RuntimeException('Workflow template has no LoadImage node to inject the uploaded photo into');
        }

        return $graph;
    }
}
