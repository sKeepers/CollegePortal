# EPIC-001: Security Review

Дата: 08.07.2026.  
Окружение: DEV `/srv/college-dev`.

## Краткий вывод

Базовая безопасность MVP заложена: API защищен token middleware, есть роли/permissions, audit log, production-защита для очистки демо-данных, QR содержит token без персональных данных, загрузка фото валидируется. Перед реальной эксплуатацией нужно усилить RBAC, импорт, хранение файлов и политику персональных данных.

## Права доступа

### Текущее состояние

- API защищен `api.token`.
- Для административных разделов используется permission middleware.
- Есть роли и role_user foundation.
- Admin получает повышенные права через Gate before.

### Риски

- Большая часть CRUD и импортов находится под `manage_dictionaries`, что слишком широко.
- `/access/gate` и `/access/reports` сейчас в той же группе, что и справочники/импорт.
- `/admin/audit` и `/admin/settings` находятся под `manage_users`; лучше разделить `view_audit` и `manage_settings`.
- Мобильный кабинет требует строгой связи user -> student/person.

### Рекомендации

1. Ввести детальную permission matrix.
2. Отдельные permissions: `manage_imports`, `manage_access`, `view_access_reports`, `view_audit`, `manage_settings`, `manage_identity`.
3. Добавить Policy/Gate на сущности, где важна принадлежность пользователю.
4. Проверить, что студент не может получить данные другого студента через mobile endpoints.

## Загрузка файлов

### Текущее состояние

- Фото валидируется как image, mimes `jpg,jpeg,png,webp`, max 4096 KB.
- Import принимает CSV/TXT/XLSX.
- Import files сохраняются в local storage.
- Фото хранится в public disk.

### Риски

- Нужно контролировать lifetime файлов импорта.
- Нужна политика удаления старых import files.
- Для XLSX нужен лимит размера и строк.
- Public фото требуют понимания, что URL может быть доступен при знании пути.

### Рекомендации

1. Добавить scheduled cleanup для старых import files.
2. Добавить лимит размера import файла и документировать его.
3. Для персональных фото рассмотреть signed/private access, если политика колледжа требует закрытого доступа.
4. Добавить antivirus/scanning hook для production, если файлы будут приходить из внешних источников.

## Импорт

### Риски

- Импорт работает с персональными данными.
- Ошибки импорта и preview rows сохраняются в `import_jobs` JSON.
- Export/import history может содержать ФИО, email, телефоны.

### Рекомендации

1. Дать доступ к `/admin/import` только ограниченной роли.
2. Audit log должен фиксировать preview/validate/confirm/export template.
3. Добавить retention policy для `import_jobs` и stored files.
4. Не отправлять подробные stack traces в API errors.
5. Для production запретить импорт без явного подтверждения роли/окружения, если это будет требование регламента.

## API и обработка ошибок

### Положительно

- Используются Form Request Validation во многих модулях.
- Universal import возвращает структурированные ошибки строк.
- Расписание проверяет конфликты.
- После security hardening 30.07.2026 API bearer-токены ищутся по индексированному SHA-256 lookup hash, имеют срок действия и не требуют перебора всех активных пользователей с `Hash::check`.
- Для `/api/auth/login` добавлен rate limit по IP и email, для authenticated API добавлен базовый rate limit по пользователю.

### Риски

- Не все контроллеры используют отдельные Request-классы; часть admin/reference/users валидируется в контроллере.
- Ошибки RuntimeException в импорте нужно держать пользовательскими, без утечки технических деталей.
- Frontend пока хранит bearer token в `localStorage`; для production нужно перейти на HttpOnly Secure cookie или Laravel Sanctum/session-based flow с CSRF-защитой.
- Персональные данные, private files и backup archives требуют отдельной задачи на шифрование, retention и контроль доступа.
- Нужно отдельно проверить rate limiting для scan endpoints и публичных export/download маршрутов.

### Рекомендации

1. Перевести frontend-auth с `localStorage` bearer token на HttpOnly Secure cookie/Sanctum или другой session flow без доступа JavaScript к токену.
2. Добавить rate limiting или anti-repeat policy на `/access/scan` уже есть частично по duplicate window, но нужен серверный rate limit.
3. Унифицировать error responses.
4. Для admin endpoints постепенно перенести inline validation в Request classes.
5. Вынести encryption-at-rest для ПДн, private storage и backup archives в отдельную production-readiness задачу.

## QR и Digital Identity

### Положительно

- QR содержит token, а не ФИО/email/телефон.
- Есть статусы active/suspended/revoked/expired.
- Есть отзыв QR.

### Риски

- Token rotation/expiration policy еще не описана как production-процесс.
- Нужно защитить QR endpoint и выдачу от массового скачивания.
- Нужны правила, кто может выпускать и отзывать пропуска.

### Рекомендации

1. Вынести срок действия QR в настройки и политики.
2. Добавить отдельные permissions для issue/revoke/view QR.
3. Добавить audit на просмотр QR, если это важно для регламента.
4. Рассмотреть периодическую ротацию token для мобильных QR.

## Audit

Audit уже есть, это сильная сторона. Следующие меры:

- запретить редактирование audit logs через API;
- добавить retention/archive policy;
- добавить request_id middleware;
- не хранить лишние персональные данные в old/new values, если это не требуется.

## Production readiness security checklist

Перед PROD:

- сменить все demo-пароли;
- проверить `.env` и secret handling;
- отключить debug;
- настроить HTTPS;
- настроить security headers и доверенный TLS-сертификат;
- ограничить доступ к admin routes;
- проверить backup encryption;
- проверить политику персональных данных;
- согласовать retention для import files, audit logs и access events.

## Выполнено: SEC-001 API token hardening

По результатам `PROJECT_ANALYSIS.md` от 30.07.2026 закрыт первый критический backend-slice без изменения frontend-контракта авторизации:

- добавлены `users.api_token_lookup_hash` и `users.api_token_expires_at`;
- login сохраняет bcrypt-хэш только как rollback-compatible поле, а request authentication использует индексированный SHA-256 lookup;
- middleware больше не загружает всех активных пользователей и не выполняет `Hash::check` по каждому запросу;
- истекшие и legacy bcrypt-only токены отклоняются;
- logout очищает все token-поля;
- добавлен `API_TOKEN_TTL_MINUTES`, по умолчанию 720 минут;
- добавлен throttling для login и authenticated API;
- добавлены regression tests для login token metadata, TTL, legacy-token rejection и login rate limit.

Оставшиеся блокеры production-security:

- заменить bearer token в `localStorage` на HttpOnly Secure cookie/Sanctum или другой backend-controlled session flow;
- шифровать чувствительные персональные данные и private backup artifacts;
- закрыть public storage для персональных файлов там, где требуется private/signed access;
- настроить TLS, HSTS, CSP и другие security headers;
- приблизить CI/test database к PostgreSQL production semantics.

## Выполнено: REFACTOR-003

Подготовлены production-документы безопасности:

- `docs/PRODUCTION_SECURITY_CHECKLIST.md` — обязательные проверки перед PROD;
- `docs/PRODUCTION_DEPLOYMENT_READINESS.md` — оценка готовности, ручные проверки, блокеры и rollback plan.

Ключевой вывод: перенос в PROD должен быть заблокирован до проверки `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, backup/restore, ролей, demo-паролей, retention import-файлов и public settings.
