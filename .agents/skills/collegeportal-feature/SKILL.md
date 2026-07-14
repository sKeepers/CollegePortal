---
name: collegeportal-feature
description: Реализовывать новые feature-задачи CollegePortal от инвентаризации до PR и green CI. Использовать для новых модулей, API, UI и инфраструктурных возможностей; не использовать для узкого bugfix, чистого UAT или release-only задачи.
---

# Workflow feature-задачи

1. Прочитать `AGENTS.md`, `PROJECT_CONTEXT.md`, `ROADMAP.md`, `TASKS.md` и локальные инструкции модуля.
2. Проверить environment, remote, branch, HEAD и clean state. Создать отдельные branch/worktree от актуального `origin/develop`.
3. Инвентаризировать execution path, текущие модели/API/UI/tests и совместимые паттерны.
4. Составить короткий план с рисками, миграциями, RBAC, Audit и обратной совместимостью.
5. Реализовать минимальный законченный вертикальный сценарий. Не менять PROD/UAT и несвязанные ветки.
6. Выполнить migration smoke, targeted tests, полный backend suite, frontend build и route/API smoke.
7. Для UI выполнить browser UAT и responsive checks; обезличить screenshots/traces.
8. Обновить документацию и выполнить `git diff --check`, forbidden-file и secret scan.
9. Создать commit, push, PR в `develop` и дождаться green CI.

## Stop-gates

Остановиться при несинхронизированном `develop`, dirty/conflicting worktree, неясной миграции данных, необходимости реальных credentials, падении тестов/CI, обнаружении секрета или расширении задачи до PROD/UAT.

## Отчет

Указать среду, branch/commits, изменения, миграции, tests/assertions, build, browser checks, security, ограничения, PR и CI.
