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
