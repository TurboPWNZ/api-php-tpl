## База данных (illuminate/database)

Проект использует [illuminate/database](https://github.com/laravel/framework/tree/12.x-stub/illuminate/database) (Eloquent ORM + Schema Builder) в standalone-режиме через Capsule.

### Подключение

Связь с БД настраивается в `src/config/config.php` (шаблон — `config.php.tpl`):

```php
'db' => [
    'tablePrefix' => '',
    'dsn' => 'mysql:host=localhost;dbname=mydb;charset=utf8mb4',
    'user' => 'root',
    'password' => 'secret',
],
```

Bootstrap — класс `Api\db\DatabaseManager`, инициализируется автоматически в `index.php`:

```php
// index.php
\Api\db\DatabaseManager::boot();  // один раз на запрос, идемпотентен
```

После `boot()` доступны:
- `Model` — Eloquent-модели (`Api\db\Payment`, ...)
- `Capsule::schema()` — для миграций
- `DatabaseManager::connection()` — raw PDO-соединение

---

### Миграции

Миграции хранятся в `database/migrations/` и запускаются через `artisan`:

| Команда | Описание |
|---------|----------|
| `php artisan migrate` | Выполнить ожидающие миграции |
| `php artisan migrate:rollback` | Откатить последнюю группу |
| `php artisan migrate:refresh` | Полный откат + повторный прогон |
| `php artisan migrate:status` | Показать статус миграций |

В Docker:

```bash
docker compose exec apache php artisan migrate
```

#### Как создать новую миграцию

1. Создать файл в `database/migrations/` с именем `YYYY_MM_DD_HHMMSS_description.php`:

```php
<?php
// database/migrations/2026_09_06_000001_create_users_table.php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('users');
    }
}
```

2. Запустить:

```bash
docker compose exec apache php artisan migrate
```

#### Использование модели

```php
use Api\db\Payment;

// Получить ожидающие оплаты
$pending = Payment::getPendingPayments();

// Обновить статус
Payment::updatePaymentStatus('order_123', Payment::STATUS_COMPLETED);

// Оплаты пользователя (только completed, пагинация)
$payments = Payment::getPaymentsByUser(42, limit: 20, offset: 0);

// Любой Eloquent-запрос
$latest = Payment::where('user_id', 42)
    ->completed()
    ->first();
```

---

## Отправка почты

Для отправки почты используется библиотека [PHPMailer](https://github.com/PHPMailer/PHPMailer).

### Пример использования:

```php
use Api\Mailer;
use Api\Configurator;

$mailer = new Mailer(Configurator::getMailConfig());

// Отправка простого текстового письма
$mailer->send('user@example.com', 'Тема письма', 'Текст письма');

// Отправка HTML-письма
$mailer->send('user@example.com', 'Тема', '<h1>Привет!</h1><p>Это HTML письмо</p>', 'text/html');

// Отправка пользователю по ID
$mailer->sendToUser(1, 'Тема письма', 'Текст письма');
```

### Настройка отправки

В файле `src/config/mail.php` можно указать:

- `driver` — драйвер отправки (`sendmail`, `smtp`) — по умолчанию: `sendmail`
- `smtp.host` — SMTP сервер (по умолчанию: `smtp.gmail.com`)
- `smtp.port` — SMTP порт (по умолчанию: `587`)
- `smtp.encryption` — тип шифрования (`tls`, `ssl`) — по умолчанию: `tls`
- `smtp.username` — SMTP логин
- `smtp.password` — SMTP пароль
- `from.email` — email отправителя
- `from.name` — имя отправителя

### Драйверы

- **sendmail** — использует встроенную функцию `mail()` PHP (по умолчанию)
- **smtp** — использует SMTP-сервер для отправки почты