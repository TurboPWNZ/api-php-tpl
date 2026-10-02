<?php

namespace Api\app\controllers;

use Api\db\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TemplateController
{
    /**
     * GET /v1/template/list — доступные шаблоны для выбора на главной.
     */
    public function list(Request $request): JsonResponse
    {
        $baseUrl = $request->getSchemeAndHttpHost();
        $items = Template::withoutBlobs()->available()->get()
            ->map(fn (Template $t) => $t->toApiArray($baseUrl))
            ->values()
            ->all();

        return new JsonResponse(['success' => true, 'data' => $items]);
    }

    /**
     * GET /v1/template/{id}/preview/{before|after} — картинка превью из БД.
     * Без JWT: это <img src>, заголовок авторизации туда не передать, а
     * превью шаблонов — не приватные данные. URL версионируется (?v=),
     * поэтому кэш вечный.
     */
    public function preview(Request $request, string $id, string $side): Response
    {
        $template = Template::query()
            ->select(["preview_{$side} AS bytes", "preview_{$side}_mime AS mime"])
            ->where('id', (int)$id)
            ->first();

        if ($template === null || $template->bytes === null) {
            return new Response('Not found', 404);
        }

        return new Response($template->bytes, 200, [
            'Content-Type' => $template->mime,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
