# Текущая работа

## Назначение

Файл фиксирует состояние рабочей сессии CollegePortal. Это точка входа для нового чата, агента или инженера после прочтения `AGENTS.md`.

Обновлять перед окончанием сессии, переходом к существенно другой задаче и после каждого развёртывания на DEV.

## Обновлено

- Date: 2026-08-02
- Local worktree: `C:\!Projects\CollegePortal\.worktrees\sync-001`
- DEV checkout: `/home/andale/CollegePortal`

## Git-состояние

- Active worktree branch: `sync/sync-001-local`
- Last deployed DEV checkpoint: `1d88712d4`
- DEV branch: `feature/uat-002-1-final-stabilization`
- GitHub branch: `origin/feature/uat-002-1-final-stabilization` развёрнута на DEV до `1d88712d4`.
- Локальный HEAD: `f72ab81` на ветке `sync/sync-001-local`; последний известный DEV HEAD: `1d88712d4`.
- `SYNC-001` объединил GitHub `SEC-001` с DEV UAT-002.2 и был развёрнут на DEV.
- На DEV применена миграция `2026_07_30_010000_add_lookup_and_expiration_to_api_tokens`.
- Проверки после развёртывания: `php artisan test` — `347 passed (2215 assertions)`; `npm run build` завершилась успешно; health endpoint вернул `200`.
- На DEV развёрнуты карточка оценок и адаптация профилей устройств после успешной production-сборки.
- Legacy `ScheduleLesson` теперь открывает конкретный `JournalLesson` через `legacy_schedule_lesson_id`; создание идемпотентно, переносит тему занятия и формирует roster студентов.
- Проверки исправления: `JournalEngineApiTest` — `10 passed (64 assertions)`; `npm run build` завершилась успешно.
- Teacher journal: список студентов растягивает страницу, подсказки проходной показывают результат для каждого студента, недоступные административные быстрые действия скрыты, а `Мои занятия` показывает все занятия преподавателя.
- Последняя проверка: `JournalEngineApiTest` — `10 passed (67 assertions)`.
- Подписанный журнал показывает подтверждение перед подписью. Преподаватель может направить запрос редактирования, а пользователь с `journal.reopen` одобряет или отклоняет его; одобрение переоткрывает журнал и фиксируется в audit.
- Миграция DEV `2026_08_02_010000_create_journal_edit_requests_table` применена. `JournalEngineApiTest`: `11 passed (75 assertions)`.
- Pending запросы отображаются в журнале администратора и на admin dashboard. Обе точки открывают конкретный журнал для одобрения или отклонения, а после решения запрос исчезает из обоих списков. `JournalEngineApiTest`: `11 passed (81 assertions)`.
- Dashboard использует единое представление версии для widget и `О системе`; masonry grid пересчитывает spans через `ResizeObserver`, устраняя пустоты между карточками.
- `POST admin/demo-data/reset` очищает рабочие данные DEV через `TRUNCATE ... RESTART IDENTITY CASCADE`, сохраняя пользователей, роли, permissions, settings и справочники. Устаревшая кнопка `Очистить демо-данные` заменена на `Очистить рабочие данные DEV`.
- Reset выполнен на DEV: сохранены 12 пользователей, 11 ролей, 156 permissions и 26 settings; `Student`, `Teacher`, `ScheduleLesson`, `JournalLesson` и `JournalEditRequest` имеют по 0 записей.
- До изменений создана проверенная резервная копия PostgreSQL DEV: `/home/andale/CollegePortal/backups/pre-change-20260802-1745.sql`, 438786 bytes, SHA-256 `d306daa896499d89eb60fdcefcdc051fc750d3d9223ca32d58b2066811f14112`.
- Кадровый контур: подразделения и должности создаются без ручного кода, автоматически получают уникальный code и показывают ошибку сохранения. Сотрудник с `is_teacher` получает профиль `Teacher` для той же `Person`; дополнительные `EmployeeAssignment` поддерживают внутреннее и внешнее совместительство.
- Пропуск автоматически отзывается при удалении или деактивации `Student`, `Teacher` либо `User`; событие фиксируется в audit.
- Проверка `Заявления_принятые_20260802203415.xls`: 243 заявления, все СНИЛС проходят checksum. Импорт не выполнялся: текущий обработчик не использует `SnilsService` и не переносит паспорт в foundation documents; UI дополнительно блокирует apply при числе строк, отличном от 149.
- Незакоммиченные изменения: MVP полного архивирования PostgreSQL. Добавлены защищенный API `/api/admin/database-backups`, `pg_dump`/`pg_restore` через безопасный массив аргументов, аварийный снимок перед restore, audit, UI в `DataManagementPage`, Pinia store и тесты. Изменены `backend/Dockerfile`, `backend/Dockerfile.release`, `backend/routes/api.php`, `frontend/src/pages/admin/DataManagementPage.vue`, `frontend/src/router/routes.js`; добавлены backend service/controller/request/config/tests и frontend store.
- Проверки task: `git diff --check` пройдена. `php artisan test tests/Unit/PostgresBackupServiceTest.php tests/Feature/DatabaseBackupApiTest.php`, `vendor/bin/pint --test` и `npm run build` не запущены: в worktree отсутствуют `backend/vendor`, `frontend/node_modules`, а `php` отсутствует в `PATH`; `npm run build` остановилась до Vite из-за отсутствующего executable.
- DEV и PROD не изменялись; коммит и развёртывание не выполнялись.

## Доступ к DEV

- Browser portal: `https://84.54.208.134:5443`
- Internal DEV portal: `https://192.168.34.114:5443`
- Health check: `http://127.0.0.1:8001/health/live`
- Containers: `docker compose -f /home/andale/CollegePortal/docker-compose.yml`
- Never put passwords, tokens, private keys, or personal data in this file.

## Текущая задача

MVP admin-архивирования и восстановления PostgreSQL (ожидает установку зависимостей и проверку).

GitHub Issues доступны на DEV только для чтения через `gh`. Текущий обзор: [GitHub Issue Review 2026-08-01](GITHUB_ISSUE_REVIEW_2026-08-01.md); не изменять Issues без явной задачи.

Принятые требования:

1. Вход: показ пароля, сохранение браузером и выбор постоянной или сессионной авторизации.
2. Преподаватель: ограниченный собственными занятиями журнал с посещаемостью и оценками, без редактора расписания.
3. Студент: только личное расписание, режимы недели и месяца, детальные оценки без рабочего пространства журнала преподавателя.
4. Проходная: компактный сканер для телефона с низкой нагрузкой.
5. Адаптивная маршрутизация по ролям для телефона, планшета, HD, FullHD и широких desktop-экранов.

## Проверенные checkpoint

- `328572e`: динамический QR, защита от повторного использования, ограничение журнала студента.
- `c3c2a6d`: доступ студента к расписанию и мобильная навигация по датам.
- `88b1f83`: компактное рабочее пространство мобильного сканера.
- `86108ff`: личный QR-пропуск с обратным отсчётом.

## Следующие действия

1. Установить PHP/Composer-зависимости и frontend dependencies в этом worktree, затем запустить `php artisan test tests/Unit/PostgresBackupServiceTest.php tests/Feature/DatabaseBackupApiTest.php`, `vendor/bin/pint --test` и `npm run build`.
2. Проверить вручную под пользователем с `settings.manage`: создание снимка, список metadata, обязательное `RESTORE`, создание аварийного снимка и audit-записи.
3. Перед развёртыванием проверить volume/retention для `storage/app/private/postgresql-backups`, чтобы архивы переживали пересоздание backend container.

## Блокеры

- Автоматизированные проверки завершены. Браузерный UAT под teacher требует интерактивной сессии с учётной записью и не заменяется API-тестами.
- Рабочие профили очищены намеренно; до ручного UAT необходимо создать новые связанные записи преподавателя и студента.
- ФИС-выгрузка не должна применяться до устранения обхода checksum СНИЛС и отсутствия переноса паспортных реквизитов.
- Автоматические тесты MVP архивирования заблокированы отсутствующими зависимостями в Windows worktree; DEV/PROD намеренно не трогались.
- PROD не изменялся.

## Чек-лист передачи

Before ending a session or opening a new chat:

1. Run `git status --short`, `git diff --check`, and `git log --oneline -10`.
2. Update this file with branch, local/DEV HEAD, uncommitted work, verified checks, blockers, and exact next actions.
3. Commit completed logical work; do not commit incomplete or unreviewed changes unless explicitly requested.
4. State whether DEV and PROD were changed.
5. Offer a new chat when the task is complete, when the context is near its practical limit, or when the next task is independent.

## Запрос для нового чата

```text
Read AGENTS.md, docs/ACTIVE_WORK.md, TASKS.md, and docs/UAT_002_REPORT.md.
Run git status --short, git diff --check, and git log --oneline -10.
Compare the local branch and DEV HEAD stated in ACTIVE_WORK.md.
Continue only the listed Next Actions. Do not discard uncommitted work.
```
