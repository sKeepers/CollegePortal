# Codex Workflow

Дата: 2026-06-30

Документ описывает стандартный порядок работы Codex над CollegePortal после настройки DEV/PROD и Git workflow.

## Основные правила

- Работать только в `/srv/college-dev`, если задача явно не разрешает другое.
- На Windows работать только в `C:\!Projects\CollegePortal`.
- Отдельные Windows worktree создавать только внутри `C:\!Projects\CollegePortal\.worktrees\<branch>`.
- Не использовать устаревшую Windows-копию проекта с нижним регистром в имени каталога и не создавать worktree рядом с проектом.
- PROD не трогать без отдельного подтверждения.
- Не выполнять реальный deploy без отдельного подтверждения.
- Не коммитить секреты, `.env`, зависимости, runtime-файлы, logs, `tmp` и backup-файлы.
- Каждая выполненная задача завершается Git checkpoint.

## Стандартный порядок

1. Перейти в DEV:

```bash
cd /srv/college-dev
```

2. Проверить текущее состояние:

```bash
git status --short --branch
```

3. Выполнить задачу.

4. Запустить проверки, подходящие задаче.

Frontend:

```bash
docker compose exec -T frontend npm run build
```

Backend/API:

```bash
docker compose exec -T backend php artisan test
```

Shell-скрипты:

```bash
bash -n scripts/deploy/*.sh
```

Полная DEV-проверка перед деплоем:

```bash
scripts/deploy/check-dev.sh
```

5. Проверить изменения:

```bash
git status
git diff --check
git diff --name-only
```

6. Убедиться, что в commit не попадут запрещенные файлы:

- `.env`;
- `backend/.env`;
- `frontend/.env`;
- `vendor/`;
- `node_modules/`;
- `tmp/`;
- logs;
- runtime/cache-файлы;
- docker volumes;
- backups.

7. Добавить файлы:

```bash
git add .
```

Если задача затрагивает рискованные области, добавлять файлы точечно:

```bash
git add docs/GIT_WORKFLOW.md TASKS.md
```

8. Проверить staged-файлы:

```bash
git status --short
git diff --cached --name-only
```

9. Сделать commit с номером задачи:

```bash
git commit -m "INFRA-005: document Codex git workflow"
```

10. Подготовить отчет пользователю:

- что изменено;
- какие проверки выполнены;
- hash commit;
- текущий `git status`;
- что PROD не трогался.

## Если проверка упала

- Исправить ошибку в DEV.
- Повторить проверки.
- Не делать commit с падающими обязательными проверками, если пользователь не просит сохранить промежуточное состояние.
- В отчете указать, что именно падало и как исправлено.

## Если изменений нет

Если задача была аналитической или после проверки файловых изменений нет:

- выполнить `git status`;
- commit не создавать;
- в отчете явно написать: `Git checkpoint не создан, потому что файловых изменений нет`.

## Deploy

Обычная работа Codex заканчивается commit в DEV. Перенос в PROD выполняется только по `docs/DEPLOYMENT.md` и только после отдельного подтверждения.
