# Деплой

Стек: PHP-бэкенд (этот репозиторий) + React-фронтенд (`../ai-gen-tg/app`) +
MySQL + ComfyUI (отдельно, обычно на GPU-сервере). Локальная разработка
крутится на Apache (`docker-compose.yml`) — **на проде веб-сервер nginx**,
и часть апачевых настроек (`.htaccess`, `mod_headers`) на нём просто не
работает. Этот документ — то, что нужно вместо них, плюс всё остальное для
первого деплоя.

`docker/nginx.conf` + `docker/php-fpm.dockerfile` + `docker-compose.nginx.yml`
в этом репозитории — не черновик, а **реально прогнанная** локально копия
прод-конфига (логин, JWT, загрузка фото, генерация через ComfyUI, отдача
`/storage/`, лимиты на размер запроса, блокировка чувствительных путей —
всё проверено запросами против поднятого nginx+php-fpm). Бэкенд-код при
переходе с Apache на nginx не меняется вообще — только веб-сервер.

---

## 1. Топология

Два разумных варианта:

**А. Разные (под)домены — рекомендуется, конфиг ниже рассчитан на него.**
`api.yourdomain.com` → этот бэкенд, `app.yourdomain.com` → фронтенд.
Проще всего: `docker/nginx.conf` — это готовый `server{}` для API-домена
как есть, без правок.

**Б. Один домен, бэкенд под `/v1/`.** В `PAYMENTS_README.md` уже есть
заметка "все API-роуты должны начинаться с `/v1/` — нужно для nginx на
проде, у него принимающий location только `/v1/...`" — если это ваш
план, все текущие роуты и так под `/v1/` (см. `src/routes/api.php`),
**кроме** `POST /account/change-password` (уже отмечено как известная
проблема в `PAYMENTS_README.md` — этот роут в таком варианте деплоя не
будет доступен, пока не переедет под `/v1/`). Конфиг тогда собирается из
одного `server{}` на весь домен: блоки `location ~ \.php$` /
`location ^~ /vendor/` и т.д. из `docker/nginx.conf` заворачиваются под
`location /v1/ { ... }` с `alias`/`root` на этот бэкенд, а `location / {}`
отдаёт статику фронтенда. Собирать такой конфиг вручную сложнее и легче
ошибиться — если нет жёсткой причины делить домен, вариант А проще и
надёжнее.

---

## 2. Бэкенд: сервер, PHP, зависимости

Ubuntu/Debian, PHP 8.2 (как в `docker/php-fpm.dockerfile`):

```bash
sudo apt update
sudo apt install -y nginx php8.2-fpm php8.2-mysql php8.2-zip php8.2-mbstring unzip git
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
```

Выложить код (например, `git clone` в `/var/www/api-php-aigen`), затем:

```bash
cd /var/www/api-php-aigen
composer install --no-dev --optimize-autoloader
```

### Права на `storage/` и `logs/`

Локально в докере пришлось делать `chmod 777` — это была подгонка под
несовпадение uid хоста и контейнера в Docker Desktop, **на проде так
делать не надо**. Правильно — отдать владение пользователю, от которого
работает php-fpm (обычно `www-data`):

```bash
sudo chown -R www-data:www-data storage logs
sudo find storage logs -type d -exec chmod 755 {} \;
```

(На фронте `HomeScreen`/`GenerationController` пишут в `storage/uploads/`
и `storage/results/` на каждый запрос — если права не настроены,
всё будет падать с `500` при первой же генерации; см. `GENERATION_README.md`
про то, как это уже один раз ловили в докере.)

### Конфиг (`src/config/config.php`)

Копируется из `config.php.tpl` — на нём НЕ должны остаться значения из
шаблона/дев-окружения:

| Ключ | Что сделать |
|---|---|
| `db.*` | Реальные прод-креды. Если сейчас там тестовая БД (`eg550228.mysql.tools`) — проверить, что это действительно та база, с которой должен работать прод, не забытый тестовый стенд. |
| `params.gtBotToken` | **Реальный токен бота** от @BotFather. В деве стоит фейковая строка `000000:LOCAL-DEV-INSECURE-NOT-A-REAL-BOT` — она устраивала мок initData (просто общий HMAC-секрет), но `createInvoiceLink`/`answerPreCheckoutQuery`/`setWebhook` реально стучатся в Telegram и с фейковым токеном будут получать `401 Unauthorized: invalid token specified` (см. `GENERATION_README.md`/переписку про оплату звёздами — именно так и было проверено). |
| `jwt.secret` | В шаблоне буквально написано `// CHANGE THIS!` — и это не для вида: это тот секрет, которым подписываются и проверяются все JWT. Сгенерировать: `openssl rand -hex 32`. |
| `payment.providers.TelegramStars.webhookSecret` | Свежий секрет **для прода**, не тот, что в деве. `openssl rand -hex 32`. Используется в `setWebhook` (шаг 5) и сверяется в `TelegramStars::verifyCallback()`. |
| `payment.providers.NOWPayments.*` | Не используется фронтом сейчас (см. `TopUpScreen.tsx` — крипто-опция закомментирована), можно оставить плейсхолдеры, пока не понадобится. |
| `params.captchaSiteKey/captchaSecretKey` | Мидлварь капчи сейчас не подключена к роуту логина (закомментирована в `api.php`) — не блокирует деплой. |
| `comfyui.domain` | **Не `host.docker.internal`** (это чисто Docker Desktop на Windows для дев-машины) и не `127.0.0.1`, если ComfyUI не на этом же хосте. Реальный адрес GPU-сервера — см. раздел 4. |
| `generate.costBase` / `costWithPrompt` | Проверить, что цифры (сейчас 4/8) те, что должны быть в проде — они же задублированы во фронте (`app/src/lib/api.ts`, `GENERATION_COST_BASE`/`GENERATION_COST_WITH_PROMPT`), поменяли на бэке — поменяйте и там. |

### Миграции

```bash
php artisan migrate --force
```

Применит все три: `payments`, `telegram_account`, `generations`.

---

## 3. nginx

Ключевые отличия от Apache-конфига (`.htaccess`), учтены в `docker/nginx.conf`:

- **`try_files`** вместо `RewriteCond %{REQUEST_FILENAME} -f` — тот же
  смысл ("файл существует → отдать как есть, иначе → `index.php`"), но
  для nginx это *не* автоматически безопасно: старый `.htaccess` отдавал
  бы вообще **любой** существующий файл, включая `config.php`,
  `composer.json`, `.git/`, `vendor/` — потому что docroot = корень
  проекта, отдельной `public/` папки нет. `docker/nginx.conf` явно
  блокирует эти пути (проверено — см. ниже).
- **`Authorization`-заголовок**. Это единственная реальная ловушка,
  которая тихо всё сломает: стоковый `fastcgi_params` в nginx **не**
  прокидывает `Authorization` в PHP (Apache+mod_php прокидывал). Без
  явного
  ```nginx
  fastcgi_param HTTP_AUTHORIZATION $http_authorization;
  ```
  все JWT-защищённые роуты будут отвечать `401 Authorization header is
  missing`, даже с правильным токеном. Проверено на реальном логине через
  nginx — без этой строки ловится сразу.
- **`client_max_body_size`**. У nginx свой лимит на тело запроса (по
  умолчанию 1M), отдельный от `upload_max_filesize`/`post_max_size` в
  php.ini — жать нужно оба. Тут выставлено `30M`, под 25M/30M из
  `docker/php-fpm.dockerfile`. Проверено: 25 МБ доходит до PHP, 35 МБ
  нginx режет `413` ещё до PHP.
- **CORS на `/storage/`**. `index.php` сам ставит
  `Access-Control-Allow-Origin: *`, но только для запросов, которые до
  него доходят — а файлы из `/storage/` (фото/результаты генераций)
  отдаются напрямую, `index.php` для них не выполняется вообще. Фронт
  их не только показывает через `<img>` (CORS не нужен), но и
  `fetch()`-ит (для «Повторить» и «Скачать» — нужен). Без
  `add_header Access-Control-Allow-Origin "*" always;` в `location
  /storage/` браузер эти fetch() будет блокировать.

### Конфиг

Готовый, протестированный: **`docker/nginx.conf`** в этом репозитории.
На bare-metal сервере он подключается почти как есть — единственная
правка:

```nginx
# было (докер, php-fpm — соседний контейнер):
fastcgi_pass php-fpm:9000;

# на голом сервере (php8.2-fpm через unix-сокет, стандартный путь Ubuntu):
fastcgi_pass unix:/run/php/php8.2-fpm.sock;
```

Плюс `server_name` (сейчас `_` — заглушка на все хосты) и `root` (сейчас
`/var/www/html` — путь для докер-контейнера, на сервере — реальный путь
до кода, например `/var/www/api-php-aigen`).

Установка на сервере:

```bash
sudo cp docker/nginx.conf /etc/nginx/sites-available/api.yourdomain.com
# правим fastcgi_pass / server_name / root, как выше
sudo ln -s /etc/nginx/sites-available/api.yourdomain.com /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### HTTPS

Telegram требует HTTPS и для URL Mini App, и для webhook'а. Проще всего —
certbot:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d api.yourdomain.com
```

(аналогично для домена фронтенда — см. раздел 4).

### Проверить, что реально работает (то же самое, чем проверялось локально)

```bash
# 401 без токена — маршрутизация и мидлварь живы
curl -i https://api.yourdomain.com/v1/auth/check

# 404 — чувствительные файлы не отдаются
curl -o /dev/null -w '%{http_code}\n' https://api.yourdomain.com/composer.json
curl -o /dev/null -w '%{http_code}\n' https://api.yourdomain.com/.git/HEAD
curl -o /dev/null -w '%{http_code}\n' https://api.yourdomain.com/src/Configurator.php

# login → get-account с Bearer-токеном — если тут 401 "Authorization header
# is missing", см. пункт про fastcgi_param HTTP_AUTHORIZATION выше
```

---

## 4. ComfyUI

Как и в деве, ComfyUI — отдельный процесс (обычно на GPU-сервере, не там
же, где nginx/php-fpm), у него нет собственной аутентификации. Отсюда два
момента для прода:

1. **Не открывать ComfyUI (`8188`) в интернет.** Доступ — только с
   бэкенд-сервера: приватная сеть/VPN, либо firewall/security group,
   пускающие `8188` только с IP бэкенда. Открытый ComfyUI — это чужой
   бесплатный GPU-компьют и доступ к файлам на диске того сервера.
2. **`comfyui.domain` в конфиге** — реальный адрес, по которому бэкенд
   действительно достучится до ComfyUI (внутренний IP/приватный DNS), не
   `host.docker.internal` (это чисто Docker Desktop на Windows) и не
   `127.0.0.1`, если это разные машины.

Быстрая проверка с бэкенд-сервера:

```bash
curl -m 5 http://<comfyui-host>:8188/system_stats
```

---

## 5. Telegram-бот

1. **Создать бота** через [@BotFather](https://t.me/BotFather) (или
   использовать существующего — токен идёт в `params.gtBotToken`, см.
   раздел 2).
2. **Привязать Mini App**: у @BotFather — `/newapp` (или Bot Settings →
   Menu Button / Mini App) → указать URL фронтенда
   (`https://app.yourdomain.com`).
3. **Зарегистрировать вебхук платежей** (одноразово, вручную — из кода
   это не делается) — подробно уже расписано в `PAYMENTS_README.md`,
   коротко:

   ```bash
   curl -X POST "https://api.telegram.org/bot<BOT_TOKEN>/setWebhook" \
     -d "url=https://api.yourdomain.com/v1/payment/notyfication/TelegramStars" \
     -d "secret_token=<payment.providers.TelegramStars.webhookSecret из конфига>" \
     -d 'allowed_updates=["pre_checkout_query","message"]'
   ```

   Проверить: `curl "https://api.telegram.org/bot<BOT_TOKEN>/getWebhookInfo"`
   — `url` должен совпадать, `last_error_message` — пусто.

Полный цикл оплаты звёздами (создание инвойса → `openInvoice` в
Mini App → вебхук → зачисление баланса) можно проверить только внутри
настоящего Telegram-клиента с настоящим ботом — ни браузер, ни мок
initData этого не заменяют. Зато саму логику зачисления/идемпотентности
можно проверить, не дожидаясь этого — см. `PAYMENTS_README.md` (или
просто прогнать `setWebhook` → оплатить самому боту небольшую сумму
звёзд один раз, для проверки).

---

## 6. Фронтенд

### Устрановка с релизного тега

```bash
gh auth login   # один раз, с токеном/логином, у которого есть доступ к репо
gh release download v0.0.1 --repo TurboPWNZ/ai-gen-tg --pattern "dist.zip" --dir /tmp/dist-v0.0.1
unzip /tmp/dist-v0.0.1/dist.zip -d /var/www/app   # замени путь на реальную папку сервера
```

```bash
cd app
npm install
```

Создать `app/.env.production` (обычный текстовый файл, не секрет — можно
коммитить, в `.gitignore` игнорируются только `*.local`):

```bash
VITE_API_BASE_URL=https://api.yourdomain.com
```

(если решили на вариант Б из раздела 1 — единый домен — тут будет тот же
домен, что у фронта, просто с `/v1` в путях, которые уже зашиты в
`src/lib/api.ts`).

`VITE_TELEGRAM_DEV_BOT_TOKEN` трогать не нужно — мок Telegram (`src/lib/telegramMock.ts`)
активируется только при `import.meta.env.DEV` (то есть в `npm run dev`),
в проде эта ветка недостижима даже при случайном открытии на localhost —
уже проверялось (`vite build` + `vite preview` на localhost мок не
подхватывает).

```bash
npm run build
```

Собирает статику в `app/dist/` — обычный SPA-билд без клиентского роутера
(навигация в приложении — не по URL, а по внутреннему состоянию
`route` в `App.tsx`), так что nginx-конфиг для фронта — самый простой
статический хостинг, без `try_files … /index.html` под произвольные пути:

```nginx
server {
    listen 443 ssl;
    server_name app.yourdomain.com;

    root /var/www/ai-gen-tg/app/dist;
    index index.html;

    location / {
        try_files $uri =404;
    }

    ssl_certificate     /etc/letsencrypt/live/app.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.yourdomain.com/privkey.pem;
}
```

HTTPS — тем же certbot'ом, что и для API-домена (раздел 3).

---

## 7. Чек-лист после деплоя

- [ ] `curl https://api.yourdomain.com/v1/auth/check` → `401` (не `500`/таймаут)
- [ ] `/composer.json`, `/.git/HEAD`, `/src/Configurator.php` на API-домене → `404`
- [ ] Открыть Mini App через реального бота в Telegram → логин проходит,
      баланс приходит реальный (значит `gtBotToken` верный и initData
      настоящий проверяется корректно)
- [ ] Загрузить фото → генерация доходит до `ready` (значит ComfyUI
      реально достижим с прод-сервера, `/storage/` пишется и отдаётся)
- [ ] Пополнение звёздами хотя бы раз до конца (`openInvoice` →
      успешная оплата → баланс реально увеличился) — единственное, что
      нельзя проверить без настоящего бота и настоящего Telegram-клиента
- [ ] `logs/*.log` пишутся и ротируются (см. ниже) — не растут бесконечно

## 8. Мелочи по эксплуатации

- **Логи** (`Api\components\Log`, `logs/*.log`) ничем не ротируются
  сами — если объём важен, добавить `logrotate`-конфиг
  (`/etc/logrotate.d/api-php-aigen`) на `logs/*.log`.
- **Обновление кода**: `git pull`, `composer install --no-dev
  --optimize-autoloader` (если менялись зависимости), `php artisan
  migrate --force` (если добавлялись миграции) — рестарт php-fpm не
  обязателен (opcache по умолчанию не кеширует между запросами
  агрессивно настолько, чтобы это требовалось), но `sudo systemctl
  reload php8.2-fpm` не помешает после деплоя зависимостей.
- **Секреты** (`jwt.secret`, `gtBotToken`, `webhookSecret`,
  `db.password`) — только в незакоммиченном `src/config/config.php`
  (уже в `.gitignore`), не в `config.php.tpl`.
