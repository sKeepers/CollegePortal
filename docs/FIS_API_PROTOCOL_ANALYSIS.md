# Анализ протокола ФИС API

## Статус

Протокол не реализован на основании догадок. Официальная спецификация и XSD пока не загружены в DEV из-за недоступности официальной страницы с сервера.

## Что должно быть извлечено строго из спецификации

- SOAP version;
- WSDL и bindings;
- XML namespaces;
- методы импорта;
- формат аутентификации;
- формат `PackageID`;
- методы получения статуса и ошибок;
- ограничения размера;
- кодировка;
- XML signature / PKI requirements;
- сжатие/base64;
- retry/idempotency semantics;
- timeout;
- коды ошибок;
- отличие TEST и PROD.

## Безопасная архитектурная позиция

До загрузки официальной спецификации:

- `SoapFisTransport` не формирует SOAP envelope и не отправляет payload;
- `FisPackageBuilder` блокирует генерацию официального XML при `schema_version=pending-official-spec`;
- XSD validation блокируется, если `FIS_API_XSD_PATH` не указывает на локальную официальную XSD;
- production endpoint заблокирован feature flag `FIS_API_ALLOW_PRODUCTION_SEND=false`.

## TEST endpoint

Предоставленный адрес:

```text
http://10.0.3.1:8383/api/import/importservice.svc
```

Проверяется командой:

```bash
php artisan fis:connection-check --environment=test
```

Команда выполняет только TCP connect без credentials и SOAP payload.
