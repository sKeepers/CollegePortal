# SOAP-контракт ФИС ГИА и Приема

Статус: частично подтвержден, транспортный контракт не подтвержден.

## Источник

Файлы WSDL/XSD/DISCO получены с TEST endpoint `10.0.3.1:8383` через ViPNet-ПК и хранятся только в private storage. В Git фиксируется только redacted summary.

## Подтвержденная часть

| Поле | Значение |
|---|---|
| Service | `ImportService` |
| Contract / portType | `IImportService` |
| Target namespace | `http://tempuri.org/` |
| WSDL operations | 17 |
| DISCO | содержит `contractRef` на TEST `?wsdl` |
| Production | не использовался, запрещен |

## Неподтвержденная часть

Опубликованный TEST WSDL не содержит:

- `wsdl:binding`;
- `wsdl:port`;
- `soap:binding`;
- `soap:operation`;
- `soapAction`;
- endpoint address внутри WSDL;
- SOAP fault contracts.

Поэтому в коде запрещено создавать SOAP envelope, Content-Type, SOAPAction или headers из предположений.

## Read-only кандидаты

`GetTestDictionariesList` и `GetTestDictionaryDetails` присутствуют в `portType` и имеют пустой request wrapper. Они являются кандидатами для GIA-004 только после официального подтверждения transport binding/action/authentication.

## Правило реализации

Gateway adapter может реализовывать новый SOAP method только если одновременно известны:

1. SOAP version.
2. Binding name/type.
3. SOAPAction или официальное подтверждение отсутствия SOAPAction.
4. Request wrapper namespace and body.
5. Response wrapper namespace and body.
6. Authentication model.
7. Read-only semantics.
8. TEST-only endpoint.

На 16.07.2026 эти условия не выполнены.

## GIA-003.1 metadata result

`?wsdl`, `?WSDL` и `?singleWsdl` проверены повторно. Полный transport contract не найден:

- binding count: `0`;
- port count: `0`;
- SOAP version count: `0`;
- SOAPAction count: `0`;
- endpoint address count: `0`.

DISCO указывает только на `?wsdl`; WSDL imports указывают только на `?xsd=xsd0` и `?xsd=xsd1`. Отдельный WSDL с binding не опубликован через найденный dependency graph.

До получения официального binding/action/authentication contract выбранный кандидат `GetTestDictionariesList` остается только кандидатом. SOAP POST запрещен.
