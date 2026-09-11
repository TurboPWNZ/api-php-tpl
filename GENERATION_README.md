# Генерация (фото → ComfyUI i2i)

`GenerationController` (`src/app/controllers/GenerationController.php`) —
загрузка фото, постановка image-to-image генерации в очередь ComfyUI и
опрос результата. Хранение — таблица `generations` + файлы под `storage/`.

| Файл | Роль |
|---|---|
| `src/components/comfyui/ComfyUIClient.php` | HTTP-клиент к ComfyUI: `uploadImage()`, `queuePrompt()`, `getHistory()`, `viewImage()`. Обычный curl, без зависимостей. |
| `src/components/comfyui/I2IWorkflow.php` | Патчит JSON-шаблон воркфлоу под конкретный запрос (картинка + промпт). Завязан на конкретную структуру `comfy/workflow/i2i_host_gen.json` (один узел `LoadImage`, один `RandomNoise`) — при смене воркфлоу проверить заново. |
| `src/components/Storage.php` | Директории под фото пользователя (`storage/uploads/{telegram_id}`, `storage/results/{telegram_id}`), с явным `chmod(0777)` — см. «Права на storage/» ниже. |
| `src/db/Generation.php` | Eloquent-модель, `toApiArray()` — представление для фронта (абсолютные URL, ISO-дата). |

## Роуты

| Метод | Роут | Auth | Назначение |
|---|---|---|---|
| POST | `/v1/generation/create` | JWT | `multipart/form-data`: `image` (файл, JPEG/PNG/WebP, до 20 МБ) + `prompt` (опционально). Списывает стоимость, ставит генерацию в очередь. Ответ: `{success, data: {id, status:'processing', ...}}`. |
| GET | `/v1/generation/status/{id}` | JWT | Опрашивается фронтом, пока `status=processing`. Когда ComfyUI закончил — скачивает результат в `storage/results/`, помечает `ready`/`failed`. |
| GET | `/v1/generation/list` | JWT | История генераций текущего пользователя, новые сверху. |

## Конфиг (`src/config/config.php`, шаблон — `config.php.tpl`)

```php
'comfyui' => [
    'domain' => 'http://host.docker.internal:8188', // см. ниже про докер
    'i2i' => [
        'workflow' => 'comfy/workflow/i2i_host_gen.json',
        'systemPromt' => 'make funny', // всегда добавляется первым; юзер-промпт — через ", "
    ],
],
'generate' => [
    'costBase' => 4,       // без описания
    'costWithPrompt' => 8, // с описанием
],
```

### `domain` и Docker: 127.0.0.1 vs host.docker.internal

Бэкенд бежит в `docker-compose` (`apache.dockerfile`). Если ComfyUI — на
хосте (как сейчас: Windows, `127.0.0.1:8188` из браузера/хоста), то
**из контейнера** `127.0.0.1` — это сам контейнер, а не хост. Поэтому в
конфиге — `host.docker.internal` (Docker Desktop прокидывает это имя на
хост из любого контейнера). Если когда-нибудь ComfyUI тоже окажется в
`docker-compose` — сюда пойдёт имя того сервиса.

### Стоимость

`costBase`/`costWithPrompt` в `GenerationController::create()` —
выбирается по наличию непустого `prompt`. **Дублируется во фронте**
(`app/src/lib/api.ts` — `GENERATION_COST_BASE`/`GENERATION_COST_WITH_PROMPT`,
только для отображения до отправки запроса) — пока нет эндпоинта отдачи
цен, при изменении стоимости нужно поправить оба места.

## Как это работает

1. Атомарное списание баланса (тот же паттерн `where('balance', '>=', $cost)`
   + `decrement`, что раньше был в удалённом `GameController` — см.
   комментарий в `DatabaseManager` про `MYSQL_ATTR_FOUND_ROWS`).
2. Фото сохраняется в `storage/uploads/{telegram_id}/` (переживает
   генерацию — это и есть «Было»).
3. То же фото грузится в ComfyUI (`POST /upload/image`), `I2IWorkflow::build()`
   патчит шаблон (картинка в `LoadImage`, промпт через плейсхолдер
   `{{user_promt}}`, рандомный `noise_seed` — иначе шаблон с фиксированным
   `seed=0` каждый раз давал бы одинаковый результат).
4. `POST /prompt` → `prompt_id` → создаётся строка `generations`
   (`status=processing`).
5. Если что-то из шагов 2–4 упало — баланс возвращается, генерация не
   создаётся, `500`/`502` фронту.
6. `GET /v1/generation/status/{id}`: пока `GET /history/{prompt_id}`
   пустой — всё ещё `processing`. Как только появился — либо ошибка
   (баланс возвращается, `status=failed`), либо есть картинка-результат:
   скачивается через `GET /view` и кладётся в `storage/results/{telegram_id}/`,
   `status=ready`.

## `storage/` и `.htaccess`

`storage/uploads/*` и `storage/results/*` — не в гите (см. `.gitignore`),
только `.gitkeep`. Отдаются напрямую Apache (корневой `.htaccess`: «файл
существует — отдай как есть», в обход `index.php`) — поэтому у них
**свой** `storage/.htaccess` с `Access-Control-Allow-Origin: *`
(`index.php` эту шапку не проставляет, раз не выполняется; без неё
фронт не может сделать `fetch()` картинки с другого origin — например,
для «Повторить», см. `app/README.md`). Модуль `mod_headers` включён в
`apache.dockerfile`.

### Права на storage/ (и logs/) в докере

Контейнер пишет файлы от `www-data`, а `storage/`/`logs/` смонтированы с
хоста (другой uid). `Storage::userDir()` подстраховывается `chmod(0777)`
на директориях пользователя, но **родительские** `storage/uploads` и
`storage/results` должны быть открыты на запись заранее — при первом
разворачивании на новой машине:

```bash
chmod 777 storage storage/uploads storage/results logs
```
