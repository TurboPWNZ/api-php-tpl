<?php

namespace Api\db;

use Illuminate\Database\Eloquent\Model;

/**
 * Шаблон генерации (см. миграцию create_templates_table). Превью лежат в
 * БД (MEDIUMBLOB) — в списках их не выбираем, см. scopeWithoutBlobs().
 */
class Template extends Model
{
    /** Лимит превью: GD нет ни локально, ни на проде — не ужимаем, а не пускаем большие. */
    const PREVIEW_MAX_BYTES = 1024 * 1024;
    const PREVIEW_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    const LIST_COLUMNS = [
        'id', 'name', 'sort', 'enabled', 'cost', 'cost_with_prompt', 'prompt', 'prompt_guest',
        'preview_before_mime', 'preview_after_mime', 'created_at', 'updated_at',
    ];

    protected $table = 'templates';

    protected $fillable = [
        'name',
        'sort',
        'enabled',
        'cost',
        'cost_with_prompt',
        'prompt',
        'prompt_guest',
        'workflow',
    ];

    protected $hidden = ['workflow', 'preview_before', 'preview_after'];

    protected $casts = [
        'sort' => 'int',
        'enabled' => 'bool',
        'cost' => 'float',
        'cost_with_prompt' => 'float',
    ];

    public function scopeWithoutBlobs($query)
    {
        return $query->select(self::LIST_COLUMNS);
    }

    public function scopeAvailable($query)
    {
        return $query->where('enabled', true)->orderBy('sort')->orderBy('id');
    }

    /** Шаблон по умолчанию — первый доступный (для клиентов без template_id). */
    public static function defaultTemplate(): ?self
    {
        return static::withoutBlobs()->available()->first();
    }

    public function costFor(bool $withPrompt): float
    {
        return $withPrompt ? (float)$this->cost_with_prompt : (float)$this->cost;
    }

    public function systemPromptFor(bool $isGuest): string
    {
        return (string)($isGuest && $this->prompt_guest !== null && $this->prompt_guest !== '' ? $this->prompt_guest : $this->prompt);
    }

    public function toApiArray(string $baseUrl): array
    {
        // ?v= — превью отдаются с вечным кэшем, при замене меняется URL.
        $version = $this->updated_at?->getTimestamp() ?? 0;
        $previewUrl = fn (string $side, ?string $mime) => $mime
            ? "{$baseUrl}/v1/template/{$this->id}/preview/{$side}?v={$version}"
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'cost' => (float)$this->cost,
            'costWithPrompt' => (float)$this->cost_with_prompt,
            'beforeUrl' => $previewUrl('before', $this->preview_before_mime),
            'afterUrl' => $previewUrl('after', $this->preview_after_mime),
        ];
    }

    /**
     * Валидирует файл превью и кладёт его в preview_{side}. Сохранение — на вызывающем.
     *
     * @throws \InvalidArgumentException
     */
    public function setPreviewFromFile(string $side, string $path): void
    {
        if (!in_array($side, ['before', 'after'], true)) {
            throw new \InvalidArgumentException("Unknown preview side: {$side}");
        }
        if (!is_file($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }
        $mime = mime_content_type($path) ?: '';
        if (!in_array($mime, self::PREVIEW_MIMES, true)) {
            throw new \InvalidArgumentException("{$path}: must be JPEG, PNG or WebP (got {$mime})");
        }
        $size = filesize($path);
        if ($size > self::PREVIEW_MAX_BYTES) {
            throw new \InvalidArgumentException(sprintf('%s: %.1f MB, max %.1f MB — уменьшите картинку', $path, $size / 1048576, self::PREVIEW_MAX_BYTES / 1048576));
        }

        $this->forceFill([
            "preview_{$side}" => file_get_contents($path),
            "preview_{$side}_mime" => $mime,
        ]);
    }

    /**
     * Читает workflow из файла и проверяет, что это JSON с узлом LoadImage.
     *
     * @throws \InvalidArgumentException
     */
    public static function readWorkflowFile(string $path): string
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new \InvalidArgumentException("Workflow file not found: {$path}");
        }
        $graph = json_decode($raw, true);
        if (!is_array($graph)) {
            throw new \InvalidArgumentException("Workflow is not valid JSON: {$path}");
        }
        $hasLoadImage = false;
        foreach ($graph as $node) {
            $hasLoadImage = $hasLoadImage || (($node['class_type'] ?? null) === 'LoadImage');
        }
        if (!$hasLoadImage) {
            throw new \InvalidArgumentException("Workflow has no LoadImage node: {$path}");
        }
        if (!str_contains($raw, '{{user_promt}}')) {
            throw new \InvalidArgumentException("Workflow has no {{user_promt}} placeholder for the prompt: {$path}");
        }

        return $raw;
    }
}
