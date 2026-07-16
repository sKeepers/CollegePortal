# Запрос в техподдержку ФИС ГИА и Приема

## Назначение

Документ содержит готовый текст обращения за полным официальным SOAP/WSDL-контрактом TEST-сервиса ФИС ГИА и Приема. Перед отправкой нужно указать официальный адрес получателя и при необходимости уточнить юридическое наименование организации.

## Тема письма

Запрос полного WSDL и параметров TEST-сервиса ФИС ГИА и Приёма

## Адресат

Подтвержденный адрес техподдержки в репозитории и доступных официальных материалах не найден. Не использовать неподтвержденные адреса. Адрес должен быть выбран ответственным сотрудником из официального канала ФЦТ/ФИС.

## Организация-заявитель

Ставропольский краевой колледж искусств.

Если для официального обращения требуется юридически точная форма, перед отправкой заменить строку выше на полное юридическое наименование из регистрационных документов организации.

## Текст письма

Добрый день.

Ставропольский краевой колледж искусств готовит интеграцию информационной системы CollegePortal с TEST-контуром сервиса автоматизированного взаимодействия ФИС ГИА и Приема.

Используем TEST endpoint:

```text
http://10.0.3.1:8383/api/import/ImportService.svc
```

В ходе проверки выполнялись только HTTP GET/HEAD metadata-запросы. Production endpoint `10.0.3.1:8080`, SOAP POST, Import, Validate, Delete и реальные персональные данные не использовались.

Полученные metadata-документы:

- `?wsdl` / `?WSDL` возвращает WSDL с `portType`, `message`, `types` и 17 operations;
- `?singleWsdl` возвращает объединенный WSDL с теми же operations и XSD types;
- `?disco` содержит `contractRef` на `?wsdl`;
- `?xsd=xsd0` и `?xsd=xsd1` успешно возвращают XSD.

Подтвержденные элементы контракта:

- service element: `ImportService`;
- contract / portType: `IImportService`;
- namespace: `http://tempuri.org/`;
- operation count: 17;
- кандидат для безопасной read-only проверки: `GetTestDictionariesList`.

Проблема: опубликованные `?wsdl` и `?singleWsdl` не содержат элементов, необходимых для корректного SOAP-вызова без предположений:

- `wsdl:binding`;
- `wsdl:service` с рабочим `wsdl:port`;
- `soap:binding` или `soap12:binding`;
- `soap:operation` или `soap12:operation`;
- `soapAction`;
- endpoint address внутри WSDL;
- fault contracts;
- подтвержденную модель transport/payload authentication.

Просим предоставить полный официальный metadata contract TEST-сервиса или подтвердить параметры вручную:

1. Полный WSDL с `binding`, `service`, `port`, `soap:address`.
2. SOAP version: SOAP 1.1 или SOAP 1.2.
3. Content-Type для SOAP request.
4. SOAPAction для read-only метода `GetTestDictionariesList`.
5. Request/response wrapper namespace и body для `GetTestDictionariesList`.
6. Модель transport authentication: none / Basic / Windows / certificate / другое.
7. Модель payload authentication: `Login`, `Pass`, `InstitutionID` в body или иной механизм.
8. Наличие MEX или отдельного metadata endpoint, если binding публикуется отдельно от `?wsdl` / `?singleWsdl`.
9. Актуальную спецификацию сервиса и пример безопасного read-only запроса для TEST-контура.
10. Подтверждение, что `GetTestDictionariesList` является безопасным read-only TEST-методом.

Пароли, токены, private config, XML payload, SOAP body и персональные данные в письме не прикладываются.

## Приложение

Техническая сводка без содержимого WSDL/XSD/DISCO подготовлена в `docs/FIS_SUPPORT_REQUEST_APPENDIX.md`.
