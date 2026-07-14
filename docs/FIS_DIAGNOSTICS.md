# Диагностика ФИС

## Назначение

Маршрут `/fis/diagnostics` показывает фактическую готовность цепочки Portal -> Gateway -> ViPNet -> TEST ФИС. Диагностика read-only и не вызывает Import.

## Проверки

- Gateway configuration и `/health`;
- версия и capabilities Gateway;
- состояние FIS adapter;
- маршрут ЗКСПД;
- TEST endpoint и используемая схема транспорта;
- наличие WSDL/XSD/DISCO;
- SOAP version, binding и operations из parser;
- готовность authentication;
- готовность dictionary/read-only operations.

Если официальный WSDL отсутствует, SOAP, Dictionary и ReadOnly получают статус `blocked`. Интерфейс не подменяет это состояние демонстрационным успехом.

## API

```text
GET  /api/fis/diagnostics
POST /api/fis/diagnostics/run
GET  /api/fis/communication-logs
```

Требуется permission `fis.outbound.view`.

`GET /api/fis/diagnostics` возвращает snapshot конфигурации без активного сетевого probe. `POST /api/fis/diagnostics/run` выполняет доступные безопасные Gateway checks. Import и production не вызываются.

## FIS Communication Log

Таблица `fis_communication_logs` хранит:

- timestamp;
- method/path;
- request id;
- duration;
- status;
- HTTP code;
- SOAP Fault code/message, если Gateway вернул их;
- технический error code;
- разрешенные metadata: gateway version, latency, operation, endpoint class.

Не сохраняются:

- SOAP/XML/JSON payload;
- ФИО и другие персональные данные;
- login/password;
- token, shared secret, HMAC signature;
- WSDL/XSD содержимое.

## Интерпретация статусов

| Статус | Значение |
|---|---|
| `ok` / `confirmed` | Проверка выполнена и подтверждена фактическим ответом/контрактом |
| `configured` | Настройка присутствует, но соединение могло не проверяться |
| `ready_for_probe` | Контракт загружен, требуется контролируемый вызов |
| `blocked` | Не выполнено обязательное условие |
| `failed` | Реальная проверка завершилась ошибкой |
| `observed` | Зафиксирована конфигурация без утверждения безопасности транспорта |

## Production

Диагностика всегда возвращает `production_enabled=false`. Endpoint `:8080` не вызывается.
