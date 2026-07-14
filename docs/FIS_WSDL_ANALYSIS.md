# Анализ WSDL ФИС ГИА и Приема

> Сформировано автоматически командой `php artisan fis:analyze-contract` с явно указанным локальным путем к доступной официальной XSD.

Дата анализа: 14.07.2026 16:07:23 UTC

## Stop-gate

Официальный WSDL не загружен. SOAP version, binding, actions, методы, request/response и faults не подтверждены. Import и read-only SOAP-вызовы запрещены.

## Файлы

| Артефакт | Статус | SHA-256 |
|---|---|---|
| WSDL | missing | — |
| XSD | loaded | 7158ae7d523d3b08784a29ed0cdb4ace025695e30526285ebabb3d93c093f840 |
| DISCO | missing | — |

## Контракт

- Target namespace: `не определен`
- SOAP versions: `не определены`
- Authentication: `unknown_until_official_contract_loaded`
- Bindings: 0
- Services: 0
- Operations: 0

## Методы

| Method | Request | Response | SOAP Action | Faults |
|---|---|---|---|---|
| — | — | — | — | Ожидается официальный WSDL |

## Подтвержденная XSD

- Target namespace: `отсутствует`
- Root elements: `PackageData`, `Root`
- Payload AuthData elements: `Login`, `Pass`, `InstitutionID`

XSD подтверждает payload-level `AuthData`, но не определяет HTTP/SOAP transport authentication. SOAP envelope, action, binding и transport берутся только из официального WSDL/DISCO.
