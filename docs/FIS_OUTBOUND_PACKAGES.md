# Исходящие официальные пакеты ФИС

## Назначение

Новый outbound-контур отделен от существующего импорта ФИС XLS/XLSX в CollegePortal.

- Inbound: `/admin/import`, загрузка экспорта ФИС в CollegePortal.
- Outbound: `/api/fis/outbound/*`, подготовка, XSD-валидация и тестовая отправка пакетов из CollegePortal в ФИС.

## Таблицы

- `fis_outbound_packages`;
- `fis_outbound_package_events`;
- `fis_external_mappings`.

## Статусы

`draft`, `generated`, `validation_failed`, `validated`, `queued`, `sending`, `sent`, `accepted`, `processing`, `completed`, `rejected`, `failed`, `cancelled`.

## API

- `GET /api/fis/outbound/spec-info`
- `GET /api/fis/outbound/packages`
- `POST /api/fis/outbound/packages`
- `GET /api/fis/outbound/packages/{id}`
- `POST /api/fis/outbound/packages/{id}/generate`
- `POST /api/fis/outbound/packages/{id}/validate`
- `POST /api/fis/outbound/packages/{id}/send-preview`
- `POST /api/fis/outbound/packages/{id}/send`
- `POST /api/fis/outbound/packages/{id}/refresh-status`
- `POST /api/fis/outbound/packages/{id}/cancel`
- `GET /api/fis/outbound/packages/{id}/events`
- `GET /api/fis/outbound/packages/{id}/download`

## Ограничения FIS-API-001

- Production send всегда заблокирован.
- Официальная генерация XML заблокирована до загрузки XSD/spec.
- Mock transport доступен для DEV/test без обращения к ФИС.

## FIS-GATEWAY-001

Outbound packages can use Gateway diagnostics for safe TEST checks. Real `DoValidate`, `DoImport` and `DoImportApplicationSingle` remain disabled until official application XSD and authentication are confirmed.
