# Карта методов ФИС ГИА и Приема

## Статус на 14.07.2026

Официальный WSDL/DISCO версии 4.9 не загружен в Linux DEV. Поэтому ни один SOAP-метод ФИС пока не считается подтвержденным. Список ниже разделяет API CollegePortal Gateway и предполагаемые FIS SOAP operations.

## Подтвержденные методы Gateway

Эти методы подтверждены исходным кодом CollegePortal Gateway. Они не являются методами ФИС.

| Метод | Назначение | Изменяет данные ФИС |
|---|---|---|
| `GET /health` | Состояние службы Gateway | Нет |
| `GET /version` | Версия службы | Нет |
| `GET /capabilities` | Возможности Gateway и адаптеров | Нет |
| `GET /adapters` | Список адаптеров | Нет |
| `GET /adapters/fis/health` | Состояние FIS-адаптера | Нет |
| `POST /adapters/fis/zkspd/check` | Проверка маршрута к TEST ФИС | Нет |
| `POST /diagnostics/run` | Техническая диагностика | Нет |
| `GET /diagnostics/latest` | Последний результат диагностики | Нет |

## SOAP-методы ФИС

| Группа | Подтвержденные операции | Статус |
|---|---|---|
| Service information | Нет | Ожидается WSDL/DISCO |
| Dictionaries | Нет | Ожидается WSDL и официальная спецификация |
| Check/validate | Нет | Ожидается WSDL и request schema |
| Status/result | Нет | Ожидается WSDL и response schema |
| Import | Нет | Запрещен задачей GIA-001 |

Существующие названия `GetTestDictionariesList`, `GetTestDictionaryDetails`, `GetInstitutionInfo` и `GetTestCheckApplication` в раннем Gateway foundation не считаются официально подтвержденными. Их нельзя использовать для реального вызова, пока parser не найдет точные операции и SOAP Action в официальном WSDL.

## Автоматическое построение карты

После размещения официальных файлов в private storage:

```bash
php artisan fis:analyze-contract --write-doc=docs/FIS_WSDL_ANALYSIS.md
```

Parser извлекает `service`, `port`, `binding`, SOAP version, transport, operation, input/output message, faults, headers и SOAP Action. XML загружается с `LIBXML_NONET`, поэтому внешние сущности и сетевые импорты не выполняются.

## Gate для первого read-only вызова

Вызов разрешается только после одновременного выполнения условий:

1. SHA-256 WSDL/XSD/DISCO зафиксированы в manifest.
2. Operation и SOAP Action получены parser-ом, а не заданы вручную.
3. Request type и обязательные поля подтверждены спецификацией.
4. Способ аутентификации подтвержден.
5. Gateway доступен с DEV, FIS TEST доступен с Gateway.
6. Operation документирован как read-only.

До этого gate `Import`, validate и предполагаемые read-only SOAP operations остаются выключенными.
