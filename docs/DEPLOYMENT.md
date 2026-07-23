# Deployment

Canonical environment inventory is maintained in docs/ENVIRONMENTS.md. This document describes deployment actions and must not be used as the sole source for current DEV/UAT/PROD state. DEV to PROD

Дата: 2026-06-30

Документ описывает безопасный процесс переноса проверенных изменений CollegePortal из DEV в PROD на Ubuntu-сервере `192.168.34.104`.

## Окружения

PROD не меняется без отдельного подтверждения.

| Окружение | Путь | Frontend | Backend/API | PostgreSQL |
| --- | --- | ---: | ---: | ---: |
| DEV | `/srv/college-dev` | `5174` | `8001` | `5433` |
| PROD | see `docs/ENVIRONMENTS.md` | see `docs/ENVIRONMENTS.md` | see `docs/ENVIRONMENTS.md` | see `docs/ENVIRONMENTS.md` |

Текущий PROD path и deployment state не дублируются здесь. Используйте `docs/ENVIRONMENTS.md`; перенос PROD должен быть отдельной инфраструктурной задачей.

## Принципы деплоя

- DEV является рабочей зоной подготовки изменений.
- PROD считается стабильным окружением.
- Реальный деплой выполняется только после проверки DEV и создания backup PROD.
- Данные PROD не удаляются автоматически.
- Скрипт деплоя требует явного ручного подтверждения.
- Rollback выполняется только из заранее созданного backup.

## Скрипты

Скрипты находятся в DEV-копии проекта:

- `/srv/college-dev/scripts/deploy/check-dev.sh`
- `/srv/college-dev/scripts/deploy/backup-prod.sh`
- `/srv/college-dev/scripts/deploy/deploy-to-prod.sh`
- `/srv/college-dev/scripts/deploy/rollback-prod.sh`

## Проверка DEV

Перед деплоем выполнить:

```bash
cd /srv/college-dev
scripts/deploy/check-dev.sh
```

Скрипт проверяет:

- что запущены контейнеры `college_dev_*`;
- что DEV frontend отвечает на `http://127.0.0.1:5174/dashboard`;
- что DEV API выполняет login через `http://127.0.0.1:8001/api/auth/login`;
- что `npm run build` проходит в DEV frontend-контейнере.

## Backup PROD

Backup подготавливается отдельной командой и не запускается автоматически:

```bash
cd /srv/college-dev
scripts/deploy/backup-prod.sh
```

Backup создается в `/srv/backups/college-portal/<timestamp>/` и включает:

- архив файлов PROD-проекта;
- dump PROD PostgreSQL через контейнер `college_portal_postgres`.

Перед backup скрипт проверяет путь PROD и контейнеры PROD.

## Порядок деплоя

1. Проверить DEV:

```bash
cd /srv/college-dev
scripts/deploy/check-dev.sh
```

2. Создать backup PROD:

```bash
cd /srv/college-dev
scripts/deploy/backup-prod.sh
```

3. Запустить деплой:

```bash
cd /srv/college-dev
scripts/deploy/deploy-to-prod.sh
```

4. Прочитать план действий, который выведет скрипт.

5. Ввести точное подтверждение:

```text
DEPLOY_TO_PROD
```

После подтверждения скрипт синхронизирует код из DEV в PROD без `--delete`, не перезаписывает `.env`-файлы и выполняет минимальные команды обслуживания PROD.

## Rollback

Rollback выполняется только вручную и только из выбранного backup:

```bash
cd /srv/college-dev
scripts/deploy/rollback-prod.sh /srv/backups/college-portal/<timestamp>
```

Скрипт выводит план восстановления и требует точное подтверждение:

```text
ROLLBACK_PROD
```

Rollback восстанавливает файлы проекта и PostgreSQL dump из backup. Перед rollback нужно понимать, что это операция над PROD.

## Проверка после деплоя

После деплоя проверить:

```bash
curl -I http://127.0.0.1:5173/dashboard
curl -i http://127.0.0.1:8080/api/students
```

Для API без токена `401` является нормальным ответом.

Также проверить login:

```bash
curl -s \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"email":"<LOGIN>","password":"<TEMPORARY_PASSWORD>"}' \
  http://127.0.0.1:8080/api/auth/login
```

## Проверка скриптов

Синтаксис:

```bash
bash -n scripts/deploy/check-dev.sh
bash -n scripts/deploy/backup-prod.sh
bash -n scripts/deploy/deploy-to-prod.sh
bash -n scripts/deploy/rollback-prod.sh
```

Если установлен shellcheck:

```bash
shellcheck scripts/deploy/*.sh
```
