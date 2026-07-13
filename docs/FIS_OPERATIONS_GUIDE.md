# Эксплуатационный регламент ФИС API

## Подготовка

1. Получить официальную спецификацию, WSDL, XSD и тестовый клиент с <https://priem.rustest.ru/instructions>.
2. Сохранить материалы через `scripts/fis/download-official-specs.sh` или вручную в private storage.
3. Заполнить `FIS_API_XSD_PATH`, `FIS_API_WSDL_PATH`, `FIS_API_SCHEMA_VERSION` на DEV.
4. Проверить `php artisan fis:spec-info`.
5. Проверить сеть: `php artisan fis:connection-check --environment=test`.

## TEST flow

1. Создать outbound package.
2. Сформировать XML.
3. Выполнить XSD validation.
4. Выполнить send-preview.
5. Отправить только в TEST.
6. Получить PackageID.
7. Обновить статус.
8. Сохранить обезличенный отчет.

## Production

Production activation запрещена в FIS-API-001 и должна быть вынесена в FIS-API-002 после сертификации и письменного подтверждения.


## FIS-API-001.1 Operational Status

Current status: `READY FOR OFFICIAL SPECS / READY FOR ZKSPD NODE`.

A first real TEST send is blocked until all conditions are true:

- official version 4.9 spec/WSDL/XSD/test client are stored in private storage;
- manifest has SHA-256 for each file;
- authentication method is confirmed by spec;
- TEST credentials are present in `.secrets/fis-test.env`;
- either DEV or Gateway Agent has TCP access to `10.0.3.1:8383`;
- XSD validation passes for the selected minimal package.
