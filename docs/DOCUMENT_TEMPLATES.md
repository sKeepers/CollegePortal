# Шаблоны документов

Шаблон имеет статус:

- `draft`;
- `active`;
- `archived`.

Активная версия для типа документа публикуется через API. При публикации предыдущая активная версия архивируется.

Опубликованный шаблон нельзя изменять напрямую: нужно создать новую draft-версию, проверить ее и опубликовать.

Исходные пользовательские шаблоны с подписью, печатью или персональными данными не коммитятся в Git.

Демонстрационный обезличенный шаблон справки хранится в Git как seed asset:

```text
backend/resources/document-templates/student_enrollment_certificate.docx
```

При seed/install миграция копирует его в private storage runtime-путь:

```text
backend/storage/app/private/document-templates/demo/student_enrollment_certificate.docx
```

Runtime-файлы из `storage/app/private` не должны отслеживаться Git. Пользовательские шаблоны загружаются только в private storage.
