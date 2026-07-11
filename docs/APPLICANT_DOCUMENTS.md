# Applicant Documents Registry

## Назначение

Applicant Documents Registry фиксирует реальные документы заявления абитуриента отдельно от административного флага `documents_provided`. Registry используется приемной комиссией, KPI Admissions, Dashboard, bulk-операциями и будущей подготовкой данных для ФИС.

## Справочник типов документов

Типы документов хранятся в Reference Data, каталог `applicant_document_types`.

Стартовые системные типы:

| Код | Название | Обязательный | Расширения | Лимит |
| --- | --- | --- | --- | --- |
| `passport` | Паспорт | да | pdf, jpg, jpeg, png, webp | 10 MB |
| `snils` | СНИЛС | да | pdf, jpg, jpeg, png, webp | 10 MB |
| `education_document` | Документ об образовании | да | pdf, jpg, jpeg, png, webp | 15 MB |
| `photo` | Фотография | да | jpg, jpeg, png, webp | 5 MB |
| `medical_certificate` | Медицинская справка | да | pdf, jpg, jpeg, png, webp | 10 MB |
| `personal_data_consent` | Согласие на ПДн | да | pdf, jpg, jpeg, png, webp | 10 MB |

Metadata элемента справочника может задавать `required`, `allowed_extensions`, `max_size_mb`, `sort_order` и будущие условия применимости.

## Модель данных

`applicant_application_documents` хранит одну registry-строку на тип документа в заявлении:

- `applicant_application_id`;
- `document_type_id`;
- `status`: `missing`, `received`, `under_review`, `verified`, `rejected`;
- `received_at`, `received_by`;
- `verified_at`, `verified_by`;
- `rejection_reason`, `comment`, `source`.

Уникальность: `applicant_application_id + document_type_id`.

`applicant_document_files` хранит вложения документа:

- `applicant_application_document_id`;
- `original_name`;
- `stored_path`;
- `mime_type`;
- `size_bytes`;
- `checksum_sha256`;
- `uploaded_by`.

Файлы лежат в приватном storage `storage/app/private/applicant-documents/`. Публичные URL не используются.

## Комплектность

Комплектность считается по обязательным типам документов из `applicant_document_types`.

- `documents_count`: количество обязательных документов со статусом `received`, `under_review` или `verified`;
- `required_documents_count`: количество обязательных типов;
- `documents_missing_count`: сколько обязательных типов не получено;
- `documents_complete`: все обязательные типы имеют статус `received`, `under_review` или `verified`;
- `documents_verified_complete`: все обязательные типы имеют статус `verified`;
- `documents_status`:
  - `no_documents`: нет полученных обязательных документов;
  - `incomplete`: получена только часть обязательных документов;
  - `complete`: все обязательные документы получены;
  - `verified_complete`: все обязательные документы проверены.

`documents_provided` остается отдельным административным флагом подтверждения приема документов и не создает записи документов.

## API

- `GET /api/admissions/{id}/documents`;
- `POST /api/admissions/{id}/documents/{type}/receive`;
- `POST /api/admissions/{id}/documents/{type}/upload`;
- `POST /api/admissions/{id}/documents/{documentId}/verify`;
- `POST /api/admissions/{id}/documents/{documentId}/reject`;
- `PUT /api/admissions/{id}/documents/{documentId}`;
- `DELETE /api/admissions/{id}/documents/{documentId}`;
- `GET /api/admissions/{id}/documents/{documentId}/files/{fileId}/download`;
- `DELETE /api/admissions/{id}/documents/{documentId}/files/{fileId}`.

Для совместимости старый PATCH `applicant-applications/{id}/documents/{type}` продолжает работать и обновляет registry-строку.

## UI `/admissions`

Во вкладке `Документы` карточки заявления отображается checklist:

- тип документа;
- обязательность;
- статус;
- даты и пользователи приема/проверки;
- количество файлов;
- комментарий и причина отклонения;
- действия приема, загрузки, скачивания, проверки, отклонения и удаления файла.

## Upload и безопасность

Upload принимает PDF/JPG/JPEG/PNG/WebP согласно metadata справочника. Backend проверяет MIME, расширение, размер, случайное имя файла, SHA-256 checksum и принадлежность файла документу заявления.

Правила безопасности:

- файлы не публикуются через `/storage`;
- скачивание только через авторизованный endpoint;
- path traversal невозможен, клиент не управляет `stored_path`;
- audit не хранит содержимое файлов и приватные пути;
- персональные данные не добавляются в логи операции.

## RBAC

Permissions:

- `admissions.documents.view`;
- `admissions.documents.receive`;
- `admissions.documents.upload`;
- `admissions.documents.verify`;
- `admissions.documents.reject`;
- `admissions.documents.delete`;
- `admissions.documents.download`.

Роли:

- `admin`: все действия;
- `admission`: view, receive, upload, verify, reject, download;
- `director`: view, download;
- `deputy`, `study`: view;
- `teacher`, `student`, `security`: без доступа.

## Bulk

Bulk API Admissions поддерживает операции по типу документа через существующий preview/apply поток:

- `mark_document_type_received`;
- `send_document_type_review`;
- `verify_document_type`;
- `reject_document_type`.

Payload должен содержать `document_type`; для reject обязательна `reason`. Preview не изменяет БД, apply пишет Audit Log и событие заявления.

## Синхронизация legacy-данных

Команда:

```bash
php artisan admissions:sync-document-registry --dry-run
php artisan admissions:sync-document-registry --apply
```

Dry-run показывает количество заявлений, типов документов, недостающих registry-записей и legacy-строк без `document_type_id`. Apply связывает старые строки с Reference Data и создает недостающие registry-строки без создания фиктивных файлов.

## Ограничения MVP

- Нет OCR и автоматического извлечения данных из файлов.
- Нет версионирования файлов документа, только несколько вложений.
- Нет публичной ссылки на файл по design.
- Bulk UI по типам документов подготовлен backend-операциями; расширение панели массовых действий можно сделать отдельной UX-задачей.
