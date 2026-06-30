# CollegePortal — стартовый пакет для Codex и VS Code

Проект: веб-портал для колледжа искусств.

Цель: создать единую систему для учета студентов, групп, преподавателей, дисциплин, аудиторий, расписания, посещаемости, оценок, отчетов и дальнейшей интеграции с Moodle, ФРДО и ФИС ГИА.

## Рекомендуемый стек

- Backend: Laravel 12, PHP 8.4
- Frontend: Vue 3, Vite, Pinia, Tailwind CSS
- База данных: PostgreSQL 17
- Инфраструктура: Docker, Nginx, Ubuntu Server 24.04
- Интеграции: Moodle API, LDAP/Active Directory, Telegram/email

## Как использовать в VS Code

1. Распакуйте архив.
2. Откройте папку `college_portal_codex_starter` в Visual Studio Code.
3. Установите расширение Codex от OpenAI.
4. Откройте панель Codex.
5. Вставьте содержимое файла `CODEX_PROMPT_START.md`.
6. Попросите Codex начать с этапа 1: архитектура и каркас проекта.

## Главный файл для Codex

- `AGENTS.md` — постоянные инструкции для Codex.
- `CODEX_PROMPT_START.md` — первый промпт, который нужно вставить в Codex.
- `PROJECT_BRIEF.md` — описание проекта.
- `MVP_SPEC.md` — техническое задание первой версии.
- `DATABASE_SCHEMA_MVP.md` — структура БД MVP.
- `ROADMAP.md` — план разработки.
- `TASKS.md` — чек-лист задач.

## Быстрый старт разработки

### Ubuntu Server

Требования на сервере:

- Docker Engine;
- Docker Compose plugin;
- доступ в интернет для загрузки Laravel, Vue и Docker-образов.

Создать локальные env-файлы:

```bash
cp .env.example .env
```

Сгенерировать Laravel 12 в `backend/`, Vue 3/Vite в `frontend/` и собрать Docker-образ backend:

```bash
chmod +x scripts/*.sh
./scripts/init-project.sh
```

Запустить окружение:

```bash
docker compose up -d
```

### Windows

Требования на машине разработчика:

- Docker Desktop;
- PowerShell;
- доступ в интернет для загрузки Laravel, Vue и Docker-образов.

Создать локальные env-файлы:

```powershell
Copy-Item .env.example .env
```

Сгенерировать Laravel 12 в `backend/`, Vue 3/Vite в `frontend/` и собрать Docker-образ backend:

```powershell
.\scripts\init-project.ps1
```

Запустить окружение:

```powershell
docker compose up -d
```

Адреса после запуска:

- Backend через Nginx: http://localhost:8080
- Frontend Vite: http://localhost:5173
- PostgreSQL: localhost:5432

Если PowerShell блокирует запуск локального скрипта, выполните из корня проекта:

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
.\scripts\init-project.ps1
```
