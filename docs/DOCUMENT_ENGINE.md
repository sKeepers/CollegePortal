# Document Engine

Document Engine - отдельный домен CollegePortal для генерации, учета, выдачи и проверки официальных документов.

## Состав MVP

- типы документов;
- версии шаблонов;
- журнал сформированных документов;
- транзакционная регистрационная нумерация;
- DOCX-генерация в private storage;
- QR-код проверки как PNG-изображение внутри DOCX;
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

## QR и проверка

QR генерируется локально библиотекой `endroid/qr-code` и содержит только публичный URL проверки `/verify/document/{publicId}`. `publicId` является UUID и не раскрывает ID базы данных.

PNG-файл QR сохраняется рядом с документом в private storage и также включается в DOCX как `word/media/verification-qr.png`.

## PDF

PDF создается через LibreOffice/soffice, если конвертер доступен в backend-среде. Если LibreOffice отсутствует, DOCX продолжает формироваться, а PDF API возвращает понятную ошибку `PDF недоступен`.

## Ограничения MVP

Электронная подпись, печатная форма с реальной печатью и advanced validation пользовательских DOCX-шаблонов выносятся в следующие этапы.
