# GIA-003: Read-only TEST result

Статус: controlled read-only SOAP call не выполнялся.

## Почему вызов не выполнен

TEST metadata с `10.0.3.1:8383` доступна и успешно скачана через ViPNet-ПК. Gateway `0.2.10-dev` запущен, `/health`, `/version` и `/adapters` доступны.

Однако WSDL не содержит binding/port/SOAPAction, а authentication model не подтверждена официальным контрактом. Выполнение даже тестового read-only метода потребовало бы guessed SOAP envelope/action/authentication, что запрещено задачей GIA-003.

## Последнее подтвержденное состояние

| Проверка | Результат |
|---|---|
| Host | `SKKI-VR-01` |
| User | `SKKI-VR-01\CodexSandboxOnline` |
| MSBuild | `17.14.40.60911` |
| net48 probe | успешно |
| Gateway build | успешно |
| Gateway tests | успешно |
| ViPNet SSH | успешно |
| Gateway `/health` | ok, `0.2.10-dev` |
| Gateway `/version` | ok, `0.2.10-dev` |
| Gateway `/adapters` | FIS enabled, TEST, production disabled, dangerous operations disabled |
| TEST metadata download | успешно с `10.0.3.1:8383` |
| SOAP binding/action | отсутствуют в WSDL |

## Следующий unlock

Получить официальный полный WSDL или письменную спецификацию ФЦТ/ФИС, подтверждающую SOAP version, binding/action, Content-Type и authentication. После этого можно вернуться к `GetTestDictionariesList` как первому кандидату read-only TEST-вызова.

## GIA-003.1 update

Повторная TEST metadata-проверка не сняла stop-gate. `GetTestDictionariesList` присутствует в `portType`, но SOAPAction и SOAP version отсутствуют в опубликованном WSDL. Controlled read-only call не выполнялся.

Подготовлен запрос в техподдержку: `docs/FIS_SUPPORT_REQUEST.md`.
