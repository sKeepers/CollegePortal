# Git Workflow CollegePortal

Дата: 2026-06-30

Документ описывает рекомендуемый Git-процесс для разработки CollegePortal в связке DEV/PROD.

## Текущее состояние

На момент подготовки документа `/srv/college-dev` не является Git-репозиторием: в каталоге нет `.git`.

Это значит:

- текущая ветка отсутствует;
- remote отсутствует;
- `git status` недоступен;
- неотслеживаемые файлы Git не может показать до инициализации репозитория.

Инициализацию Git нужно выполнять отдельным подтвержденным действием, чтобы случайно не зафиксировать временные файлы, `node_modules`, vendor-зависимости, `.env` или runtime-данные.

## Роли окружений

- DEV: `/srv/college-dev` — место активной разработки и проверки.
- PROD: `/home/andale/college_portal` — стабильная рабочая версия.
- PROD не редактируется напрямую без отдельного подтверждения и backup.
- Перенос DEV -> PROD выполняется по процессу из `docs/DEPLOYMENT.md`.

## Основные ветки

Рекомендуемая схема:

- `main` — стабильная версия, соответствующая PROD или готовая к PROD;
- `develop` — текущая разработка и интеграция проверенных задач;
- `feature/*` — отдельные задачи, если изменение крупное или рискованное.

Примеры feature-веток:

- `feature/gui-012-teachers`
- `feature/infra-003-git-workflow`
- `feature/docs-domain-model`

## Правила работы

### Небольшие задачи

Для маленьких изменений допустима работа прямо в `develop`:

1. Внести изменения в DEV.
2. Проверить build/tests.
3. Посмотреть `git diff`.
4. Сделать один понятный commit.
5. Готовить деплой по `docs/DEPLOYMENT.md`.

### Крупные задачи

Для крупных изменений использовать отдельную ветку:

```bash
git switch develop
git switch -c feature/gui-012-teachers
```

После завершения:

```bash
git switch develop
git merge --no-ff feature/gui-012-teachers
```

## Формат коммитов

Формат:

```text
<SCOPE-ID>: <short English summary>
```

Примеры:

```text
GUI-010: add read-only journal page
INFRA-002: add safe deployment scripts
DOCS-004: update domain documentation
```

Рекомендации:

- scope должен соответствовать задаче: `GUI`, `INFRA`, `ARCH`, `DOCS`, `API`, `DB`;
- summary писать коротко, в повелительном или описательном стиле на английском;
- один commit — одна логическая задача;
- не смешивать frontend, backend и документацию в одном commit без необходимости.

## Что проверять перед коммитом

Минимально:

```bash
git status
git diff --check
git diff
```

Для frontend-задач:

```bash
cd /srv/college-dev
docker compose exec -T frontend npm run build
```

Для backend/API-задач:

```bash
cd /srv/college-dev
docker compose exec -T backend php artisan test
```

Для инфраструктурных shell-скриптов:

```bash
bash -n scripts/deploy/*.sh
```

Если установлен shellcheck:

```bash
shellcheck scripts/deploy/*.sh
```

## Подготовка изменений к деплою

1. Убедиться, что изменения закоммичены в `develop`.
2. Выполнить DEV-проверку:

```bash
cd /srv/college-dev
scripts/deploy/check-dev.sh
```

3. При необходимости слить `develop` в `main`:

```bash
git switch main
git merge --no-ff develop
```

4. Создать backup PROD:

```bash
cd /srv/college-dev
scripts/deploy/backup-prod.sh
```

5. Выполнить деплой через защищенный скрипт:

```bash
cd /srv/college-dev
scripts/deploy/deploy-to-prod.sh
```

Скрипт потребует ручное подтверждение `DEPLOY_TO_PROD`.

## Rollback

Rollback выполняется из backup:

```bash
cd /srv/college-dev
scripts/deploy/rollback-prod.sh /srv/backups/college-portal/<timestamp>
```

Скрипт потребует ручное подтверждение `ROLLBACK_PROD`.

Если используется Git, программный rollback можно готовить отдельным commit revert:

```bash
git revert <commit-sha>
```

После revert снова выполняются DEV-проверки и обычный deploy-процесс.

## Remote repository

Если remote не настроен, push не выполнять.

После отдельного решения можно подключить remote:

```bash
git remote add origin <repository-url>
git push -u origin main
git push -u origin develop
```

До настройки remote история хранится только на сервере, поэтому важны backup и аккуратные локальные commits.

## Рекомендуемая инициализация Git

Выполнять только после проверки `.gitignore` и отдельного подтверждения:

```bash
cd /srv/college-dev
git init
git switch -c main
git add .
git status
git commit -m "INFRA-003: initialize repository workflow"
git switch -c develop
```

Перед первым `git add .` обязательно проверить, что не попадут:

- `.env`;
- `backend/.env`;
- `frontend/.env`;
- `frontend/node_modules/`;
- `backend/vendor/`;
- `backend/storage/`;
- `tmp/`;
- database dumps и backup-файлы.

## Запреты

- Не делать push без настроенного и проверенного remote.
- Не коммитить `.env` и секреты.
- Не менять PROD напрямую из Git-команд.
- Не делать force push в общие ветки без отдельного решения.
- Не использовать `git reset --hard` для PROD-отката; rollback PROD выполняется через backup и документированный процесс.
