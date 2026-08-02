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
- Last deployed DEV checkpoint: `fceaaf357`
- DEV branch: `feature/uat-002-1-final-stabilization`
- GitHub branch: `origin/feature/uat-002-1-final-stabilization` развёрнута на DEV до `fceaaf357`.
- Локальная ветка `sync/sync-001-local` содержит self-scoped read-only просмотр нагрузки, улучшения журнала и workflow запроса редактирования поверх `fceaaf357`.
- `SYNC-001` объединил GitHub `SEC-001` с DEV UAT-002.2 и был развёрнут на DEV.
- На DEV применена миграция `2026_07_30_010000_add_lookup_and_expiration_to_api_tokens`.
- Проверки после развёртывания: `php artisan test` — `345 passed (2198 assertions)`; `npm run build` завершилась успешно.
- На DEV развёрнуты карточка оценок и адаптация профилей устройств после успешной production-сборки.
- Legacy `ScheduleLesson` теперь открывает конкретный `JournalLesson` через `legacy_schedule_lesson_id`; создание идемпотентно, переносит тему занятия и формирует roster студентов.
- Проверки исправления: `JournalEngineApiTest` — `10 passed (64 assertions)`; `npm run build` завершилась успешно.
- Teacher journal: список студентов растягивает страницу, подсказки проходной показывают результат для каждого студента, недоступные административные быстрые действия скрыты, а `Мои занятия` показывает все занятия преподавателя.
- Последняя проверка: `JournalEngineApiTest` — `10 passed (67 assertions)`.
- Подписанный журнал показывает подтверждение перед подписью. Преподаватель может направить запрос редактирования, а пользователь с `journal.reopen` одобряет или отклоняет его; одобрение переоткрывает журнал и фиксируется в audit.
- Миграция DEV `2026_08_02_010000_create_journal_edit_requests_table` применена. `JournalEngineApiTest`: `11 passed (75 assertions)`.
- Незакоммиченные изменения после создания checkpoint: отсутствуют. На DEV остаются только generated `frontend/public/version.json` после build и локальный `.worktrees/`.

## Доступ к DEV

- Browser portal: `https://84.54.208.134:5443`
- Internal DEV portal: `https://192.168.34.114:5443`
- Health check: `http://127.0.0.1:8001/health/live`
- Containers: `docker compose -f /home/andale/CollegePortal/docker-compose.yml`
- Never put passwords, tokens, private keys, or personal data in this file.

## Текущая задача

`UAT-002.2`: role-based portal stabilization after mobile UAT.

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

1. Повторить teacher UAT: roster отсортирован по фамилии, завершение занятия показывает уведомление, подпись требует подтверждения и блокирует редактирование.
2. Проверить запрос редактирования: teacher создаёт его для подписанного журнала, admin одобряет его и журнал снова становится редактируемым.
3. После подтверждённых результатов обновить Issues #29, #4 и #24 санитизированными UAT-доказательствами.
4. Сценарий ручного teacher UAT зафиксирован в [UAT-002 Report](UAT_002_REPORT.md).

## Блокеры

- Автоматизированные проверки завершены. Браузерный UAT под teacher требует интерактивной сессии с учётной записью и не заменяется API-тестами.
- Legacy `ScheduleLesson` без `schedule_entry_id` не может автоматически открыть или создать журнал; текущий fallback открывает отфильтрованный журнал по дате и преподавателю.
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
