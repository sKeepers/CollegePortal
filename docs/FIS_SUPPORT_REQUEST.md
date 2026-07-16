# Запрос в техподдержку ФИС ГИА и Приема

## Тема

Запрос полного WSDL и параметров TEST-сервиса автоматизированного взаимодействия ФИС ГИА и Приема

## Текст письма

Добрый день.

Наша организация готовит интеграцию с TEST-контуром сервиса автоматизированного взаимодействия ФИС ГИА и Приема.

Используем TEST endpoint:

```text
http://10.0.3.1:8383/api/import/ImportService.svc
```

В ходе проверки выполнялись только HTTP GET/HEAD metadata-запросы. Production endpoint `10.0.3.1:8080`, SOAP POST, Import, Validate, Delete и реальные персональные данные не использовались.

Полученные документы:

- `?wsdl` / `?WSDL` возвращает WSDL с `portType`, `message`, `types` и 17 operations;
- `?singleWsdl` возвращает объединенный WSDL с теми же operations и XSD types;
- `?disco` содержит `contractRef` на `?wsdl`;
- `?xsd=xsd0` и `?xsd=xsd1` успешно возвращают XSD.

Проблема: опубликованные `?wsdl` и `?singleWsdl` не содержат:

- `wsdl:binding`;
- `wsdl:port`;
- `soap:binding` или `soap12:binding`;
- `soap:operation` или `soap12:operation`;
- `soapAction`;
- endpoint address внутри WSDL;
- fault contracts.

Просим предоставить полный официальный metadata contract TEST-сервиса или подтвердить параметры вручную:

1. Полный WSDL с `binding`, `service`, `port`, `soap:address`.
2. SOAP version: SOAP 1.1 или SOAP 1.2.
3. Content-Type для SOAP request.
4. SOAPAction для read-only метода `GetTestDictionariesList`.
5. Request/response wrapper namespace и body для `GetTestDictionariesList`.
6. Модель transport authentication: none / Basic / Windows / certificate / другое.
7. Модель payload authentication: `Login`, `Pass`, `InstitutionID` в body или иной механизм.
8. Наличие MEX или отдельного metadata endpoint, если binding публикуется отдельно от `?wsdl` / `?singleWsdl`.
9. Подтверждение, что `GetTestDictionariesList` является безопасным read-only TEST-методом.

Пароли, токены, XML payload и персональные данные в письме не прикладываются.
