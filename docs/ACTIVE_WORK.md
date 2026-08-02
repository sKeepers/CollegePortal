# Текущая работа

## Назначение

Файл фиксирует состояние рабочей сессии CollegePortal. Это точка входа для нового чата, агента или инженера после прочтения `AGENTS.md`.

Обновлять перед окончанием сессии, переходом к существенно другой задаче и после каждого развёртывания на DEV.

## Обновлено

- Date: 2026-08-03
- Local worktree: `C:\!Projects\CollegePortal\.worktrees\sync-001`
- DEV checkout: `/home/andale/CollegePortal`

## Git-состояние

- Active worktree branch: `sync/sync-001-local`
- Last deployed DEV checkpoint: `9f2596dfd`
- DEV branch: `feature/uat-002-1-final-stabilization`
- GitHub branch: `origin/feature/uat-002-1-final-stabilization` развёрнута на DEV до `9f2596dfd`.
- Локальный HEAD: `67a379d` на ветке `sync/sync-001-local`; последний известный DEV HEAD: `9f2596dfd`.
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
- Полное архивирование PostgreSQL развёрнуто: защищенный API `/api/admin/database-backups`, `pg_dump`/`pg_restore` через безопасный массив аргументов, аварийный снимок перед restore, audit, UI в `DataManagementPage` и Pinia store. PostgreSQL client 17 добавлен в backend image.
- Проверки: `PostgresBackupServiceTest` и `DatabaseBackupApiTest` — `4 passed (17 assertions)`; `npm run build` завершилась успешно; health endpoint вернул `200`.
- Создан и проверен первый архив через сервис: `manual-20260802-192823-92922147-fd1d-49d4-9c7d-7449a91ddbbc.dump`, 397065 bytes. Восстановление действующей DEV-базы намеренно не запускалось.
- Исправлены права DEV runtime storage: `storage` и `storage/app/private/postgresql-backups` принадлежат `www-data`; создание второго архива от имени web-процесса подтверждено (`manual-20260802-195519-21088212-5f81-48ca-9172-a0f3f99a3eb1.dump`, 396791 bytes).
- В worktree есть незакоммиченные изменения без commit/deploy: XLSX-шаблон и импорт сотрудников, а также обязательный валидный СНИЛС для новых Student и foundation-абитуриентов.
- DEV и PROD в этой задаче не изменялись.

## Доступ к DEV

- Browser portal: `https://84.54.208.134:5443`
- Internal DEV portal: `https://192.168.34.114:5443`
- Health check: `http://127.0.0.1:8001/health/live`
- Containers: `docker compose -f /home/andale/CollegePortal/docker-compose.yml`
- Never put passwords, tokens, private keys, or personal data in this file.

## Текущая задача

Реализован локально, без commit/deploy, обязательный валидный СНИЛС для новых Student и foundation-абитуриентов. Student API, CSV и Universal Import нормализуют СНИЛС через `SnilsService`, ищут или создают Person по `snils_hash` и сохраняют `person_id`; обновления найденных импортом студентов не требуют СНИЛС. FIS dry-run/apply блокирует отсутствующий или некорректный СНИЛС до записи данных.

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

1. В окружении с PHP и зависимостями выполнить `php artisan test tests/Feature/StudentApiTest.php tests/Feature/StudentCsvApiTest.php tests/Feature/UniversalImportApiTest.php tests/Feature/Admissions/PersonApplicantManagementApiTest.php tests/Feature/FisAdmissionsImportHandlerTest.php tests/Unit/Admissions/ApplicantServiceTest.php` из `backend`.
2. Проверить вручную создание студента с корректным и некорректным СНИЛС, CSV/Universal Import и foundation-абитуриента с новым Person и существующим Person без СНИЛС.
3. Не выполнять commit или deploy без отдельного указания пользователя.

## Блокеры

- В текущем Windows worktree отсутствует команда `php`; поэтому новые автоматизированные проверки не выполнены. `git diff --check` выполнен успешно.
- DEV и PROD в этой задаче не изменялись.

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
