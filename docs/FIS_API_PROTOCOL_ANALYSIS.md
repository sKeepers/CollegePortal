# Анализ протокола ФИС API

## Статус

Протокол не реализован на основании догадок. Доступная официальная прикладная XSD версии 4.9 разобрана локально, но WSDL/DISCO и полная спецификация сервиса пока не получены из-за TLS-ошибки официальной страницы на DEV. XSD не активируется как runtime SOAP-контракт без подтвержденного WSDL.

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

## EPIC-001 / GIA-001 Checkpoint 14.07.2026

The official application XSD available in the repository was registered in DEV private storage. SHA-256: `7158ae7d523d3b08784a29ed0cdb4ace025695e30526285ebabb3d93c093f840`.

Confirmed from this XSD only:

- no `targetNamespace` is declared;
- top-level elements are `PackageData` and `Root`;
- no XSD imports/includes are declared;
- `Root/AuthData` contains `Login`, `Pass` and optional `InstitutionID`.

This confirms payload-level credentials but does not confirm HTTP/SOAP transport authentication. WSDL/DISCO remain absent, so SOAP version, service/port/binding, operations, actions, headers, request/response wrappers and faults are still unconfirmed.

`FisWsdlAnalyzer` now derives these fields from local official XML with network access disabled. The generated result is stored in `docs/FIS_WSDL_ANALYSIS.md`. Current result is an explicit stop-gate; no SOAP method was called.
