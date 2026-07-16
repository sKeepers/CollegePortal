# FIS TEST Runbook

## Назначение

Runbook описывает безопасный порядок подготовки первого read-only TEST-вызова ФИС через CollegePortal Gateway.

## Жесткие запреты

- Не использовать production endpoint `10.0.3.1:8080`.
- Не выполнять `DoImport`, `DoValidate`, `DoDelete`.
- Не использовать реальные ПДн.
- Не выводить credentials, HMAC secret, private config или raw SOAP.
- Не реализовывать SOAPAction, envelope или namespace из догадок.

## Подготовка

1. Проверить Gateway:
   - service `CollegePortalGateway` running;
   - port `8099` listening;
   - `/health` ok;
   - `/version` возвращает актуальную версию;
   - `/adapters` показывает FIS в TEST mode.
2. Проверить private contract registry:
   - WSDL;
   - DISCO;
   - XSD;
   - SHA-256 manifest;
   - XML well-formed.
3. Parser должен подтвердить:
   - service;
   - port;
   - binding;
   - SOAP version;
   - SOAPAction;
   - operations;
   - request/response wrappers;
   - faults.
4. Отдельно подтвердить authentication.
5. Выбрать один read-only method.

## Текущее состояние GIA-003

Metadata TEST доступна, но опубликованный WSDL не содержит binding/port/SOAPAction. Первый вызов запрещен до получения полного контракта или официального пояснения ФЦТ.
