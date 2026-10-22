# Почта

Пространство имён `Keel\Mail`. Symfony Mailer, подключённый как провайдер: транспорт из DSN,
`MailerInterface` Symfony и поверх него `Mailer` Keel, который подставляет настроенного
отправителя, `Reply-To` и заголовки по умолчанию и умеет отправлять после ответа. Опциональная
зависимость.

```bash
composer require symfony/mailer      # или: php bin/console keel:install mailer
```

```php
// config/mail.php
return static fn(Env $env): MailConfig => new MailConfig(
    dsn: $env->get('MAILER_DSN') ?? 'null://null',
    from: 'My app <no-reply@example.com>',      // для писем без заголовка From
    replyTo: ['support@example.com'],          // для писем без заголовка Reply-To
    headers: ['X-Mailer' => 'My app'],         // добавляются письмам, где заголовка нет
);

// config/services.php
return new ServicesConfig([MailerProvider::class]);
```

```php
use Symfony\Component\Mime\Email;

final class RegistrationController
{
    public function __construct(private readonly Users $users, private readonly Mailer $mailer, private readonly ViewRenderer $views) {}

    #[Post('/register')]
    public function register(#[FromBody] SignUp $form): array
    {
        $user = $this->users->create($form);
        $this->mailer->defer(
            new Email()
                ->to(new Address($user->email, $user->name))
                ->subject('Welcome')
                ->html($this->views->render('mail/welcome.twig', ['user' => $user]))
                ->text('Welcome, ' . $user->name),
        );

        return ['id' => $user->id];
    }
}
```

`Mailer::send()` отправляет сразу и пропускает исключение транспорта наружу
(`Symfony\Component\Mailer\Exception\TransportExceptionInterface`). `Mailer::defer()` отдаёт
подготовленное письмо в [`DeferredTasks`](runtime.md#работа-после-ответа): его отправит
`Kernel::terminate()`, когда клиент уже получил ответ, так что запрос не ждёт обмена с SMTP, а
ошибка попадает в лог, а не клиенту. Отложенная почта не долговечна: падение процесса между
ответом и отправкой её теряет; очередь вне scope Keel. Оба метода принимают необязательный
`Envelope`.

Письмо — любой `Email` или `Message` из Symfony Mime: вложения, встроенные изображения,
приоритет, `Bcc` и остальное — всё Symfony. `RawMessage` (уже собранная MIME-строка) проходит
без подстановки умолчаний.

## Транспорты

DSN — как в Symfony Mailer:

| DSN | Транспорт |
|---|---|
| `smtp://user:pass@mail.example.com:587` | SMTP со STARTTLS; `smtps://` для TLS на 465; `?verify_peer=0` и остальные опции как в Symfony |
| `sendmail://default` | локальный бинарник `sendmail` |
| `native://default` | настройки `mail()` из PHP |
| `null://null` | отбрасывает все письма (разработка без почтового сервера) |
| `memory://default` | хранит письма в `MemoryTransport` (тесты, см. ниже) |
| `mailgun+api://KEY:DOMAIN@default`, `ses+api://...`, ... | API-мост; каждый — отдельный пакет (`symfony/mailgun-mailer`, `symfony/amazon-mailer`, ...) и требует `symfony/http-client` |
| `failover(smtp://a smtp://b)`, `roundrobin(...)` | несколько транспортов |

Транспорт создаётся один раз на воркер. SMTP-транспорт держит соединение между письмами и сам
переподключается после `restart_threshold` писем или `ping_threshold` секунд простоя, так что
worker mode не требует ничего особенного; опции DSN — Symfony.

## Здоровье

`MailerProvider` регистрирует `MailerCheck` с тегом `keel.health`. Для SMTP проверка готовности
открывает сессию (соединение, `EHLO`, аутентификация) и закрывает её, сообщая задержку в `ms`;
остальные транспорты отмечаются `up` с `"probed": false` — без отправки их не проверить. Если
недоступный почтовый сервер не должен выводить приложение из готовности, зарегистрируйте
`MailerCheck` без тега в своём провайдере, загруженном после `MailerProvider`.

## Тестирование

Направьте тестовое окружение на `memory://default`: `MemoryTransport` из контейнера собирает
каждый `SentMessage` — исходное письмо (`getOriginalMessage()`), конверт и собранный MIME-текст
(`toString()`).

```php
$app = App::boot($projectDir, new Env(['APP_ENV' => 'test', 'MAILER_DSN' => 'memory://default']));
$client = TestClient::forApp($app);

$client->post('/register', ['email' => 'ann@example.com', 'name' => 'Ann'])->assertStatus(201);

$sent = $app->container->get(MemoryTransport::class)->sent();
self::assertCount(1, $sent);
self::assertSame('ann@example.com', $sent[0]->getEnvelope()->getRecipients()[0]->getAddress());
self::assertStringContainsString('Welcome', $sent[0]->toString());
```

Письма копятся всё время жизни процесса: `clear()` между тестами, делящими контейнер.
Юнит-тест собирает `new Mailer(new Symfony\Component\Mailer\Mailer(new MemoryTransport()), new MailConfig('memory://default', from: ...))`
напрямую; `defer()` требует `DeferredTasks` третьим аргументом и `run()` на нём.

## Что регистрируется

`Symfony\Component\Mailer\Transport\TransportInterface` (из DSN, `MemoryTransportFactory` впереди
фабрик Symfony), `Symfony\Component\Mailer\Mailer` как `MailerInterface`, `Keel\Mail\Mailer`,
`MemoryTransport`, `MailerCheck`. Диспетчер событий Symfony не подключается: слушатели
`MessageEvent` полного фреймворка (подпись, шифрование, глобальные заголовки) заменены
умолчаниями `MailConfig`; шина сообщений не используется.
