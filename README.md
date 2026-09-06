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