# Document Engine

Document Engine - отдельный домен CollegePortal для генерации, учета, выдачи и проверки официальных документов.

## Состав MVP

- типы документов;
- версии шаблонов;
- журнал сформированных документов;
- транзакционная регистрационная нумерация;
- DOCX-генерация в private storage;
- PDF через LibreOffice headless, если он установлен;
- публичная проверка по `verification_public_id`;
- события документа;
- RBAC и Audit.

Первый тип документа: `student_enrollment_certificate` - справка, подтверждающая обучение студента.

## Private storage

Файлы создаются в:

```text
backend/storage/app/private/generated-documents/{year}/{document_type}/{uuid}/
```

Публичная verify-страница не отдает DOCX/PDF.

## Ограничения MVP

QR в DOCX представлен через verification URL. Встраивание bitmap QR в DOCX и электронная подпись выносятся в следующие этапы.
