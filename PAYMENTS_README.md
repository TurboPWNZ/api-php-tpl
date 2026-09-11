# Платежи (NOWPayments / Telegram Stars)

Общая обвязка для пополнения баланса — `src/components/payment/`:

| Файл | Роль |
|---|---|
| `Payment.php` | Оркестрация: создание платежа (`Payment::create`), обработка нотификации/вебхука (`Payment::notification`), начисление баланса. Работает поверх Eloquent-модели `Api\db\Payment` (таблица `payments`) и не завязана на конкретного провайдера. |
| `Provider.php` | Абстрактный контракт провайдера: `createInvoice()`, `verifyCallback()`, `getOrderState()`. `updateAccountBalance()` начисляет баланс через `Api\db\TelegramAccount::findByTelegramId()`. |
| `providers/NOWPayments.php` | Криптоплатежи (USD-инвойс, редирект на nowpayments.io). |
| `providers/TelegramStars.php` | Telegram Stars (currency `XTR`, оплата прямо в Mini App). |

Контроллеры: `PaymentController` (создание инвойса, список платежей пользователя), `NotificationController` (единая точка входа для вебхуков/нотификаций всех провайдеров).

**Важно:** `payments.user_id` везде хранит **telegram_id** пользователя (как в JWT), а не внутренний `telegram_account.id` — так проще сверяться с `TelegramAccount::findByTelegramId()`.

---

## Роуты

⚠️ Все API-роуты должны начинаться с `/v1/` — нужно для nginx на проде, у него принимающий location только `/v1/...`.

| Метод | Роут | Auth | Назначение |
|---|---|---|---|
| POST | `/v1/payment/create-invoice/{provider}` | JWT (`Authorization: Bearer`) | Создать инвойс. Тело: `{ "amount": 100 }`. Ответ: `{ success, order_id, invoice_url, amount, currency }`. `{provider}` — `NOWPayments` или `TelegramStars`. |
| GET | `/v1/payment/list` | JWT | Список завершённых платежей текущего пользователя. |
| POST | `/v1/payment/notyfication/{provider}` | нет (аутентификация внутри провайдера) | Вебхук/IPN от провайдера — сюда стучится сам провайдер, не наш фронт. |

---

## Конфиг (`src/config/config.php`, шаблон — `config.php.tpl`)

```php
'params' => [
    'gtBotToken' => '...', // токен бота — уже используется для проверки initData,
                           // теперь ещё и для вызовов Bot API (createInvoiceLink, answerPreCheckoutQuery)
],

'payment' => [
    'providers' => [
        'NOWPayments' => [
            'apiKey' => 'XXX',
            'ipnKey' => 'XXX',          // HMAC-ключ для проверки подписи IPN
            'minAmount' => 15,
            'maxAmount' => 200,
            'invoice' => [
                'currency' => 'USD',
                'description' => '',
                'ipn_callback_url' => 'https://domain.com/v1/payment/notyfication/NOWPayments',
                'success_url' => '...',
                'cancel_url' => '...',
            ],
        ],
        'TelegramStars' => [
            'webhookSecret' => '<случайная строка>', // см. ниже, setWebhook(secret_token=...)
            'minAmount' => 1,     // минимум звёзд за инвойс
            'maxAmount' => 100000,
            'invoice' => [
                'currency' => 'XTR',   // не менять — это код валюты Stars в Bot API
                'title' => 'Top-up',
                'description' => 'Пополнение баланса',
            ],
        ],
    ],
],
```

`minAmount`/`maxAmount` и `invoice.currency`/`invoice.description` читаются `PaymentController::createInvoice()` по имени `{provider}` из URL — при добавлении нового провайдера достаточно завести для него такой же блок конфига, в контроллере ничего менять не нужно.

---

## Регистрация вебхука Telegram Stars (разово, вручную)

Провайдер `TelegramStars` умеет вызывать Bot API (`TelegramBotApi::call()`, `src/components/TelegramBotApi.php`), но подписку бота на апдейты (`setWebhook`) нужно оформить один раз руками — из кода бэкенда этого не делается автоматически.

1. Сгенерировать секрет и положить его в `config.php` → `payment.providers.TelegramStars.webhookSecret` (уже сгенерирован и стоит в проде; при желании сменить — сгенерировать заново тем же способом):

   ```bash
   openssl rand -hex 32
   ```

2. Зарегистрировать вебхук в Telegram (подставить реальный токен бота и актуальный домен):

   ```bash
   curl -X POST "https://api.telegram.org/bot<BOT_TOKEN>/setWebhook" \
     -d "url=https://your-domain.com/v1/payment/notyfication/TelegramStars" \
     -d "secret_token=<тот же секрет, что в config.php>" \
     -d 'allowed_updates=["pre_checkout_query","message"]'
   ```

   Ответ должен быть `{"ok":true,"result":true,"description":"Webhook was set"}`.

3. Проверить, что реально зарегистрировалось:

   ```bash
   curl "https://api.telegram.org/bot<BOT_TOKEN>/getWebhookInfo"
   ```

   Смотреть на `url` (должен совпадать) и `last_error_message` (если Telegram не смог достучаться — будет видно тут).

4. Снять вебхук (если понадобится, например для локальной отладки через `getUpdates`):

   ```bash
   curl -X POST "https://api.telegram.org/bot<BOT_TOKEN>/deleteWebhook"
   ```

**Как это работает дальше:** Telegram сам шлёт на `/v1/payment/notyfication/TelegramStars` два типа апдейтов:
- `pre_checkout_query` — на него `TelegramStars::getOrderState()` обязан ответить `answerPreCheckoutQuery` в течение 10 секунд (сверяет заказ по `order_id`/сумме и решает `ok: true/false`);
- `message.successful_payment` — на него начисляется баланс (`Payment::notification()` → `TelegramAccount::changeBalance()`), идемпотентно (повторная доставка не начислит звёзды дважды).

Аутентификация вебхука — не JWT, а заголовок `X-Telegram-Bot-Api-Secret-Token`, который Telegram присылает сам (значение задаётся при `setWebhook`) и который сверяется в `TelegramStars::verifyCallback()`.

---

## Быстрая проверка (curl)

```bash
# 1. Создать инвойс (нужен JWT из /v1/auth/login)
curl -X POST https://your-domain.com/v1/payment/create-invoice/TelegramStars \
  -H "Authorization: Bearer <JWT>" -H "Content-Type: application/json" \
  -d '{"amount":50}'
# -> {"success":true,"order_id":"PAY-...","invoice_url":"https://t.me/$...","amount":50,"currency":"XTR"}

# 2. Список платежей пользователя
curl https://your-domain.com/v1/payment/list -H "Authorization: Bearer <JWT>"
```

`invoice_url` открывается на фронте через `Telegram.WebApp.openInvoice(url, callback)` — дальше всё оплачивается и подтверждается на стороне Telegram, бэкенд только принимает вебхук.

---

## Добавление нового провайдера

1. Создать `src/components/payment/providers/<Name>.php extends Provider`, реализовать `createInvoice()`, `verifyCallback()`, `getOrderState()`.
2. Добавить блок `payment.providers.<Name>` в конфиг (минимум: то, что читает `createInvoice()` провайдера + `minAmount`/`maxAmount`/`invoice.currency` для `PaymentController`).
3. Всё остальное (роуты, контроллеры, начисление баланса, идемпотентность) уже общее — трогать не нужно.

---

## Известные смежные проблемы (не по теме платежей, но встретились рядом)

- `Api\Mailer::sendToUser()` (`src/Mailer.php:75`) использует несуществующий класс `Api\db\Account` — сейчас нигде не вызывается, но если понадобится рассылка по email, надо будет переписать на `TelegramAccount` (у которого, впрочем, пока и email-поля нет).
- `PaymentController::list()` всегда отдаёт `completed_at: null` — в таблице `payments` такой колонки нет (только `created_at`/`updated_at`).
- `POST /account/change-password` в `src/routes/api.php` — без префикса `/v1/`, той же проблемы с nginx на проде, что была у платежей.
