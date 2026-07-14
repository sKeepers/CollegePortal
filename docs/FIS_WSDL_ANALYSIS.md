# Анализ официального контракта ФИС ГИА и Приема 4.9

Дата контрольного анализа: 14.07.2026 17:40 UTC. Среда: Linux DEV, TEST-only.

## Результат

Strict stop-gate активен. Официальный WSDL и DISCO отсутствуют, поэтому SOAP version, namespace, service, port, binding, actions, operations, request/response types, faults и transport authentication не подтверждены. SOAP-вызовы не выполнялись.

## Private registry

Registry: `backend/storage/app/private/fis-specs/4.9/`. Каталог защищен локальным `.gitignore`; private-файлы не входят в Git.

| Файл | Тип | MIME | Размер | SHA-256 | Manifest |
|---|---|---|---:|---|---|
| `import-service-4.9.xsd` | XSD | `text/xml` | 284790 | `7158ae7d523d3b08784a29ed0cdb4ace025695e30526285ebabb3d93c093f840` | совпадает |
| `manifest.json` | manifest | `application/json` | 477 | `36833bfbc1ef9742a9105e1f62ed06fd9ccb2a110f14e19c8cb8f6d8ac046c98` | версия 4.9 |

WSDL: 0. XSD: 1. DISCO: 0.

XSD физически обнаружен и прошел SHA-проверку, но не активирован как полный transport contract: один payload XSD не подтверждает SOAP wrapper, binding или authentication.

## Подтверждено из XSD

- root elements: `PackageData`, `Root`;
- target namespace отсутствует;
- imports/includes: 0;
- payload `AuthData` содержит элементы `Login`, `Pass`, `InstitutionID`.

Эти элементы описывают данные payload. Они не доказывают Basic Auth, WS-Security, HTTP headers или иной transport authentication.

## Не подтверждено

- SOAP 1.1 или 1.2;
- WSDL service/port/binding;
- endpoint из WSDL;
- namespaces envelope и operations;
- SOAP Action;
- точные названия methods;
- request/response messages;
- SOAP faults;
- authentication и подпись;
- семантика read-only operations.

## Условия снятия stop-gate

1. Получить официальный WSDL и DISCO версии 4.9 через подтвержденный канал.
2. Добавить их в private registry и manifest с SHA-256.
3. Зафиксировать полную closure импортов XSD/WSDL.
4. Активировать точные пути только после проверки source/version.
5. Получить parser-ом service, port, binding, SOAP version, actions и messages.
6. Отдельно подтвердить authentication и read-only semantics по официальной документации.
7. Восстановить Gateway и подтвердить маршрут Gateway → FIS TEST.

До выполнения всех условий Import, Validate, Delete и предполагаемые read-only operations запрещены.
