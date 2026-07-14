# Карта методов ФИС ГИА и Приема

## Статус

На 14.07.2026 ни одна SOAP operation ФИС не подтверждена официальным WSDL. В коде Gateway не реализуется предположительный namespace, action или envelope.

## Подтвержденные endpoints CollegePortal Gateway

Это внутренний API Integration Hub, а не методы ФИС.

| Endpoint | Назначение | Доступ | Изменение ФИС |
|---|---|---|---|
| `GET /health` | состояние процесса Gateway | public + IP allowlist | нет |
| `GET /version` | версия Gateway | public + IP allowlist | нет |
| `GET /capabilities` | объявленные capabilities | public + IP allowlist | нет |
| `GET /adapters` | список adapters | public + IP allowlist | нет |
| `GET /adapters/fis/health` | TCP-only состояние TEST-маршрута | HMAC | нет |
| `POST /adapters/fis/zkspd/check` | TCP-only проверка `10.0.3.1:8383` | HMAC | нет |
| `POST /diagnostics/run` | техническая диагностика Gateway | HMAC | нет |
| `GET /diagnostics/latest` | последний технический результат | HMAC | нет |

Gateway проверяет только fixed allowlist `http://10.0.3.1:8383/api/import/importservice.svc`. Production `:8080` hard-disabled. TCP-check не отправляет HTTP или SOAP.

## SOAP operations ФИС

| Группа | Подтвержденные operations | Статус |
|---|---|---|
| Service information | нет | официальный WSDL отсутствует |
| Dictionaries | нет | название/action/request не подтверждены |
| Status/result | нет | operation и response type не подтверждены |
| Validate | нет | запрещено stop-gate |
| Import | нет | запрещено GIA-001 |
| Delete | нет | запрещено GIA-001 |

Названия `GetTestDictionariesList`, `GetTestDictionaryDetails`, `GetInstitutionInfo` и `GetTestCheckApplication`, встречавшиеся в раннем foundation, считаются placeholders и не вызываются.

## Будущее автоматическое построение карты

После загрузки approved bundle parser должен извлечь:

- service и port;
- binding и SOAP version;
- transport и style;
- operation;
- input/output message;
- faults;
- headers;
- SOAP Action.

Каждая read-only operation дополнительно проходит ручное подтверждение семантики и authentication. Сам факт парсинга WSDL не разрешает сетевой вызов.
