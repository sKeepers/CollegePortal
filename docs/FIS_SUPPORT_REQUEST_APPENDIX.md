# Приложение к запросу полного SOAP-контракта ФИС

Дата проверки: 2026-07-16.

## Ограничения проверки

- Проверялся только TEST endpoint `10.0.3.1:8383` через CollegePortal Gateway на ViPNet-ПК.
- Production endpoint `10.0.3.1:8080` не использовался.
- SOAP POST, Import, Validate, Delete и реальные персональные данные не использовались.
- Содержимое WSDL/XSD/DISCO, SOAP body, credentials, HMAC и private config в Git не сохранялись.

## Gateway

- Gateway version: `0.2.10-dev`.
- Gateway `/health`, `/version`, `/adapters` ранее подтверждены как доступные.
- FIS adapter работает в TEST mode.
- Production FIS endpoint отключен.
- Dangerous operations отключены до подтверждения официального контракта.

## Проверенные TEST metadata URL

| URL suffix | Метод | HTTP status | Content-Type | Size, bytes | SHA-256 | XML root / type |
| --- | --- | --- | --- | ---: | --- | --- |
| `?wsdl` | GET | 200 | XML | 12476 | `22a71a42071861ace610e7a4858514c0bc9a859b539c89f8dc9d7ac654e748c3` | WSDL definitions |
| `?WSDL` | GET | 200 | XML | 12476 | `22a71a42071861ace610e7a4858514c0bc9a859b539c89f8dc9d7ac654e748c3` | WSDL definitions |
| `?singleWsdl` | GET | 200 | XML | 23658 | `7760c8b0f019bcd042db83894ba5470ce712ed1f3b68a96e3d9e35854a4fa618` | WSDL definitions with inline types |
| `?disco` | GET | 200 | XML | 283 | `857e1132f12b93753b0a1d84e5608a9a785b7d507a7936c257dc55c79b0838e8` | DISCO discovery |
| `?xsd=xsd0` | GET | 200 | XML | 9361 | `1231785f89cd0522a23b435dc5449c89c5956c99860064286e56fc3e7c69f02b` | XML Schema |
| `?xsd=xsd1` | GET | 200 | XML | 2273 | `cff6937e7a1ed4a816ee6cb8525d75c041ff3244d8ca6100f031d20f689a521a` | XML Schema |

HEAD requests to the same metadata endpoints returned HTTP 404 with HTML response, so GET is the reliable metadata probe for this TEST service.

## Подтвержденная структура metadata

- DISCO содержит `contractRef` на `?wsdl`.
- WSDL imports указывают только на `?xsd=xsd0` и `?xsd=xsd1`.
- `?singleWsdl` содержит inline XSD types.
- Дополнительный imported WSDL с binding/service/port не обнаружен.

## Подтвержденные элементы SOAP-контракта

- Service element: `ImportService`.
- Contract / portType: `IImportService`.
- Namespace: `http://tempuri.org/`.
- Operations: 17.
- Кандидат read-only operation: `GetTestDictionariesList`.

## Отсутствующие элементы контракта

Для безопасного SOAP-вызова без предположений отсутствуют:

- `wsdl:binding`;
- `wsdl:service/wsdl:port`;
- `soap:binding` или `soap12:binding`;
- `soap:operation` / SOAPAction;
- `soap:address` endpoint;
- SOAP version;
- Content-Type;
- fault contracts;
- transport authentication;
- payload authentication.

## Stop-gate

Первый read-only SOAP-вызов не выполнялся. Причина: вызов потребовал бы угадать SOAP binding, SOAPAction, Content-Type и authentication. Следующий разрешенный шаг: получить полный официальный WSDL или письменное подтверждение параметров от ФЦТ/ФИС.
