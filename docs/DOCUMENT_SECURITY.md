# Безопасность Document Engine

Правила MVP:

- DOCX/PDF хранятся только в private storage;
- скачивание идет через controller с permission;
- публичная проверка не отдает файлы;
- Audit не должен хранить полный payload, паспортные данные, СНИЛС и содержимое файлов;
- реальные шаблоны с подписью, печатью и персональными данными не коммитятся.
- QR содержит только публичный verification URL и не содержит ФИО, email, телефон, Student ID или Person ID;
- seed-шаблон хранится в `backend/resources/document-templates`, а runtime-копия в `storage/app/private` не отслеживается Git.

Для загрузки DOCX-шаблонов используются ограничения MIME/расширения и размера. Проверка внешних ссылок, ZIP bomb и macro-enabled документов должна быть усилена в следующем этапе.
