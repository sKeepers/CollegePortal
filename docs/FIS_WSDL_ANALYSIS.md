# Анализ официального контракта ФИС ГИА и Приема 4.9

Дата контрольного анализа: 16.07.2026. Среда: Windows SKKI-VR-01 + ViPNet-ПК ZamMW, TEST-only.

## Результат GIA-003

Strict stop-gate остается активным. Официальные TEST WSDL, XSD и DISCO теперь доступны через ViPNet-ПК, но опубликованные WSDL-файлы содержат только `portType`, `message` и `types`. В них отсутствуют `wsdl:binding`, `wsdl:port`, `soap:binding`, `soap:operation`, `soapAction` и endpoint address.

Следствие: список операций подтвержден, но транспортный SOAP-контракт не подтвержден. Первый read-only SOAP-вызов не выполнялся, потому что SOAP version, Content-Type/SOAPAction и authentication model нельзя выводить из догадок.

## Private registry / TEST metadata

Источник на ViPNet-ПК:

```text
C:\CollegePortalGateway\specs\fis\active
```

Повторная загрузка TEST metadata выполнена только с `10.0.3.1:8383` во временный private diagnostics каталог:

```text
C:\CollegePortalGateway\diagnostics\gia003-contract-refresh
```

Production endpoint `10.0.3.1:8080` не использовался.

| Файл | Тип | Размер | SHA-256 |
|---|---|---:|---|
| `import-service.single.wsdl` | WSDL single | 23658 | `7760c8b0f019bcd042db83894ba5470ce712ed1f3b68a96e3d9e35854a4fa618` |
| `import-service.wsdl.xml` | WSDL | 12476 | `22a71a42071861ace610e7a4858514c0bc9a859b539c89f8dc9d7ac654e748c3` |
| `import-service.disco.xml` | DISCO | 283 | `857e1132f12b93753b0a1d84e5608a9a785b7d507a7936c257dc55c79b0838e8` |
| `import-service-wrapper.xsd` | XSD wrapper | 9361 | `1231785f89cd0522a23b435dc5449c89c5956c99860064286e56fc3e7c69f02b` |
| `microsoft-serialization.xsd` | XSD serialization | 2273 | `cff6937e7a1ed4a816ee6cb8525d75c041ff3244d8ca6100f031d20f689a521a` |

DISCO содержит `contractRef` на:

```text
http://10.0.3.1:8383/api/import/ImportService.svc?wsdl
```

## Подтверждено parser-ом

- `targetNamespace`: `http://tempuri.org/`.
- Service name: `ImportService`.
- Contract / portType: `IImportService`.
- XSD namespaces:
  - `http://tempuri.org/`;
  - `http://schemas.microsoft.com/2003/10/Serialization/`.
- Операций в `portType`: 17.

Подтвержденные операции из WSDL:

| Операция | Категория |
|---|---|
| `GetDictionariesList` | чтение, требует параметр `data` |
| `GetDictionaryDetails` | чтение, требует параметр `data` |
| `GetInstitutionInfo` | чтение, требует параметр `data` |
| `GetInstitutionPartOfInfo` | чтение, требует параметр `data` |
| `DoImport` | write/import, запрещено |
| `DoImportApplicationSingle` | write/import, запрещено |
| `GetImportResult` | чтение результата, требует контекст пакета |
| `DoValidate` | validation, запрещено в GIA-003 |
| `DoDelete` | delete, запрещено |
| `GetDeleteResult` | чтение результата delete, не использовать без delete context |
| `DoCheckApplication` | check/validation, не выбран для первого read-only |
| `DoCheckApplicationSingle` | check/validation, не выбран для первого read-only |
| `GetTestImport` | test helper, не выбран из-за неясной семантики |
| `GetTestRemove` | test helper, не выбран из-за delete semantics |
| `GetTestDictionariesList` | кандидат read-only, request wrapper пустой |
| `GetTestDictionaryDetails` | кандидат read-only, request wrapper пустой |
| `GetTestCheckApplication` | test helper, не выбран из-за check semantics |

## Не подтверждено

- SOAP 1.1 или SOAP 1.2.
- `wsdl:binding`.
- `wsdl:port` и endpoint address из WSDL.
- `soap:operation` и `soapAction`.
- Content-Type.
- SOAP headers.
- Fault contracts.
- Transport authentication.
- Достаточность payload-полей `Login`, `Pass`, `InstitutionID` как полной модели authentication.

## Stop-gate GIA-003

Первый read-only TEST-вызов заблокирован по причинам:

1. `soap_binding_missing`.
2. `soap_action_missing`.
3. `soap_version_unconfirmed`.
4. `service_port_address_missing`.
5. `transport_authentication_unknown`.
6. `read_only_call_requires_guessed_envelope`.

Разрешенный следующий шаг: получить у ФЦТ/оператора ФИС полный WSDL или официальную инструкцию, где явно указаны binding, SOAP version, SOAPAction/Content-Type и authentication для `ImportService` TEST. До этого Gateway не должен выполнять SOAP-вызовы даже для `GetTestDictionariesList`.

## GIA-003.1 Checkpoint 16.07.2026

Повторная проверка TEST metadata выполнена через ViPNet-ПК только HTTP GET/HEAD к разрешенным URL `10.0.3.1:8383/api/import/ImportService.svc`. SOAP POST не выполнялся.

### HTTP evidence

| URL suffix | HEAD | GET | Content-Type GET | Size GET | SHA-256 body |
|---|---:|---:|---|---:|---|
| `?singleWsdl` | 404 | 200 | `text/xml; charset=UTF-8` | 23658 | `7760c8b0f019bcd042db83894ba5470ce712ed1f3b68a96e3d9e35854a4fa618` |
| `?wsdl` | 404 | 200 | `text/xml; charset=UTF-8` | 12476 | `22a71a42071861ace610e7a4858514c0bc9a859b539c89f8dc9d7ac654e748c3` |
| `?WSDL` | 404 | 200 | `text/xml; charset=UTF-8` | 12476 | `22a71a42071861ace610e7a4858514c0bc9a859b539c89f8dc9d7ac654e748c3` |
| `?disco` | 404 | 200 | `text/xml; charset=UTF-8` | 283 | `857e1132f12b93753b0a1d84e5608a9a785b7d507a7936c257dc55c79b0838e8` |
| `?xsd=xsd0` | 404 | 200 | `text/xml; charset=UTF-8` | 9361 | `1231785f89cd0522a23b435dc5449c89c5956c99860064286e56fc3e7c69f02b` |
| `?xsd=xsd1` | 404 | 200 | `text/xml; charset=UTF-8` | 2273 | `cff6937e7a1ed4a816ee6cb8525d75c041ff3244d8ca6100f031d20f689a521a` |

HEAD возвращает 404 для всех metadata URL, но GET возвращает корректный XML. Поэтому проверку metadata нужно считать GET-based; HEAD не является надежным индикатором доступности сервиса.

### Dependency graph

```text
DISCO ?disco
  -> contractRef: ?wsdl

WSDL ?wsdl / ?WSDL
  -> xsd import: ?xsd=xsd0, namespace http://tempuri.org/
  -> xsd import: ?xsd=xsd1, namespace http://schemas.microsoft.com/2003/10/Serialization/

singleWsdl ?singleWsdl
  -> inline types for http://tempuri.org/
  -> inline serialization schema
```

Отдельного imported WSDL с binding/service/port не обнаружено. DISCO не содержит `soap` address и не указывает на другой metadata endpoint.

### Completeness

| Документ | definitions | types | messages | portTypes | operations | bindings | ports | soapAddresses | soapActions |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| `?singleWsdl` | 1 | 1 | 34 | 1 | 17 | 0 | 0 | 0 | 0 |
| `?wsdl` / `?WSDL` | 1 | 1 | 34 | 1 | 17 | 0 | 0 | 0 | 0 |

`wsdl:service` element присутствует, но не содержит `wsdl:port`. Полный SOAP transport contract отсутствует.

### Conclusion

Причина stop-gate не в путанице WSDL/XSD/DISCO и не в HTML/proxy response: XML корректный, hashes стабильны, DISCO и WSDL согласованы. TEST endpoint публикует неполный WSDL без binding/action metadata. Требуется официальный полный WSDL или письменное подтверждение ФЦТ по SOAP version, binding/action, endpoint и authentication.
