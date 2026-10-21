# Keel

Небольшой worker-first микро-фреймворк на PHP: PSR-7/11/14/15/17 целиком, роутинг на атрибутах с
ограниченным discovery, компилируемые контейнер и роутер, один и тот же код под php-fpm, FrankenPHP
worker mode и RoadRunner. PHP >= 8.4.

```bash
composer create-project keel/skeleton my-app
cd my-app && composer serve
```

- [Контейнер](container.md), [Роутинг](routing.md), [HTTP и ядро](http.md), [Рантаймы](runtime.md),
  [Приложение](app.md), [Сессии](session.md), [Тестирование](testing.md) описывают компоненты.
- [Аутентификация и лимиты](auth.md), [OpenAPI](openapi.md), [Здоровье и метрики](observability.md) —
  то, что нужно API в продакшене поверх ядра (с 1.1).
- [База данных](database.md), [Шаблоны](views.md), [Кэш](cache.md), [HTTP-клиент](http-client.md) —
  подключение PDO или Doctrine DBAL, Twig или Latte, кэша PSR-16 и клиента PSR-18 (с 1.2).
- [Стабильность API](api.md) фиксирует, что покрывает семантическое версионирование с 1.0.

Исходники, issue и бенчмарки: [github.com/AloneCoder/keel](https://github.com/AloneCoder/keel).
