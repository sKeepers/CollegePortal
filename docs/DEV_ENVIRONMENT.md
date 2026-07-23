# DEV Environment

Дата: 2026-06-30

Документ описывает разделение рабочего PROD-окружения и отдельного DEV-окружения CollegePortal на Ubuntu-сервере `192.168.34.104`.

## Текущее состояние

Рабочий проект считается PROD.

- PROD path: see canonical environment inventory in `docs/ENVIRONMENTS.md`.
- DEV path: `/srv/college-dev`
- Плановый будущий PROD path: `/srv/college-prod`

PROD details are intentionally not duplicated here. Use `docs/ENVIRONMENTS.md` as the canonical inventory; any PROD migration requires a separate task with downtime, backup and rollback review.

## Порты

| Окружение | Frontend | Backend/API | PostgreSQL |
| --- | ---: | ---: | ---: |
| PROD | `5173` | `8080` | `5432` |
| DEV | `5174` | `8001` | `5433` |

Адреса:

- PROD frontend: `http://192.168.34.104:5173`
- PROD API: `http://192.168.34.104:8080/api`
- DEV frontend: `http://192.168.34.104:5174`
- DEV API: `http://192.168.34.104:8001/api`

## DEV Compose

DEV использует копию проекта в `/srv/college-dev` и отдельный Docker Compose project:

- `COMPOSE_PROJECT_NAME=college_dev`
- контейнеры:
  - `college_dev_frontend`
  - `college_dev_nginx`
  - `college_dev_backend`
  - `college_dev_postgres`
- volume базы:
  - `college_dev_postgres_dev_data`

DEV не использует PROD-базу. В `/srv/college-dev/.env` указаны отдельные значения:

```env
POSTGRES_DB=college_portal_dev
POSTGRES_USER=college_dev_user
POSTGRES_PASSWORD=<SECRET>
POSTGRES_PORT=5433

HTTP_PORT=8001
FRONTEND_PORT=5174
```

Frontend DEV использует `frontend/.env`:

```env
VITE_API_BASE_URL=http://192.168.34.104:8001/api
```

## Запуск DEV

```bash
cd /srv/college-dev
docker compose up -d
```

Первый запуск или запуск после изменения Dockerfile:

```bash
cd /srv/college-dev
docker compose up -d --build
```

## Остановка DEV

```bash
cd /srv/college-dev
docker compose stop
```

Полное выключение контейнеров DEV без удаления данных:

```bash
cd /srv/college-dev
docker compose down
```

Не использовать `docker compose down -v`, если не нужно специально удалить DEV-базу.

## Миграции и демо-данные DEV

```bash
cd /srv/college-dev
docker compose exec -T backend php artisan migrate --force
docker compose exec -T backend php artisan db:seed --force
```

## Build frontend

```bash
cd /srv/college-dev
docker compose exec -T frontend npm run build
```

## Логи

Все DEV-логи:

```bash
cd /srv/college-dev
docker compose logs -f
```

Отдельные сервисы:

```bash
cd /srv/college-dev
docker compose logs -f frontend
docker compose logs -f backend
docker compose logs -f nginx
docker compose logs -f postgres
```

## Проверка

Проверить контейнеры:

```bash
docker ps --format 'table {{.Names}}\t{{.Ports}}\t{{.Status}}'
```

Проверить занятые порты:

```bash
ss -ltnp | grep -E ':(5173|5174|8080|8001|5432|5433) '
```

Проверить доступность DEV:

```bash
curl -I http://127.0.0.1:5174/dashboard
curl -i http://127.0.0.1:8001/api/students
```

Для API без токена ответ `401` является нормальным признаком, что API работает и требует авторизацию.

## Правила безопасности

- Не запускать DEV на PROD-портах `5173`, `8080`, `5432`.
- Не подключать DEV к базе `college_portal`.
- Не переименовывать и не перемещать PROD без отдельного плана.
- Не выполнять destructive-команды вроде `down -v`, `rm -rf`, `docker volume rm` без явного решения.
- Изменения в DEV сначала проверять на `5174` и `8001`, затем переносить в PROD отдельно.
