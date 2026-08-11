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

Состояние на 09.08.2026: XSD и спецификация 4.9 подключены, XML собирается и проверяется по официальной схеме — см. [FIS_OUTBOUND_PACKAGES.md](FIS_OUTBOUND_PACKAGES.md) и [FIS_DATA_MAPPING.md](FIS_DATA_MAPPING.md).

**Строка «WSDL по-прежнему нет» устарела — проверено 12.08.2026.** WSDL скачан со шлюзового узла ещё 15.07.2026 и лежит там в `specs\fis\active`; повторный запрос 12.08 вернул тот же файл. В нём семнадцать операций в `wsdl:portType`, но **ноль `wsdl:binding` и ноль портов**, поэтому сгенерировать клиента по нему нельзя — конверт SOAP 1.1 шлюз собирает руками. Из перечисленного выше списка закрыты SOAP version, namespaces, методы импорта и **формат аутентификации** (`AuthData` с логином и паролем внутри тела запроса — см. [FIS_TEST_ACCEPTANCE.md](FIS_TEST_ACCEPTANCE.md)). Открытыми остаются сами учётные данные и поведение сервиса на ошибках, которое до первого настоящего вызова не выяснить.

- `SoapFisTransport` не формирует SOAP envelope и не отправляет payload: конверт описан в WSDL, которого нет;
- `FisPackageBuilder` блокирует сборку, если официальная схема не настроена (`FIS_API_XSD_PATH`, `FIS_API_SCHEMA_VERSION`), и отказывается собирать пакет, для которого в схеме нет раздела;
- ни один идентификатор справочника не подставляется по догадке: чего нет в сопоставлениях — то причина отказа сборки;
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


## FIS-API-001.1 Checkpoint 13.07.2026

Official contract version 4.9 has not been loaded into DEV yet. The official instructions page cannot be downloaded from the current DEV/Windows environment, and no binary specification/XSD/test-client files were provided in the workspace.

Therefore the following fields remain `TBD from official spec`: SOAP version, WSDL, service/port/binding, namespaces, methods, authentication, PackageID format, status methods, error codes, payload limits, signing/certificate requirements and retry semantics.

Implementation rule remains strict: no SOAP envelope, XML namespace, auth header or package type may be implemented from guesses.

## FIS-GATEWAY-001: ViPNet Gateway Checkpoint

Actual WSDL/XSD/DISCO XML files were not available in this Codex/DEV workspace during implementation. After they are copied to `backend/storage/app/private/fis-specs/discovered/`, verify SOAP version, binding, port, transport, endpoint location, Content-Type, SOAPAction, WCF policies and authentication by XML parser before enabling any write operation.

Task input says service is `ImportService`, contract is `IImportService`, target namespace is `http://tempuri.org/`, and SOAP action follows `http://tempuri.org/IImportService/<MethodName>`. This remains pending parser verification.

`xsd0` is treated as WCF wrapper XSD with `xs:any processContents="lax"`; it is not the official application XSD and must not be used to invent real `DoValidate` or `DoImport` payloads.

## Gateway Naming Update

Portal-to-workstation communication now uses CollegePortal Gateway terminology. FIS remains the first adapter. Official SOAP/WSDL/XSD analysis is still required before enabling validate/import operations; production remains disabled.
