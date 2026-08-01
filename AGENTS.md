# Инструкции для Codex

## Project Documentation Map

- [Documentation Index](docs/README.md)
- [Project Status](docs/PROJECT_STATUS.md)
- [Active Work / Session Handoff](docs/ACTIVE_WORK.md)
- [Background Agents](docs/AGENTS.md)
- [Roadmap](ROADMAP.md)
- [Tasks](TASKS.md)
- [Changelog](CHANGELOG.md)
- [Project Context](PROJECT_CONTEXT.md)
- [Documentation Report](REPORT.md)

Ты работаешь над проектом CollegePortal — веб-порталом для колледжа искусств.

## Главная цель

Создать надежную, расширяемую веб-систему для колледжа, которая включает:

- учет студентов;
- учет групп;
- учет преподавателей;
- учет дисциплин;
- учет аудиторий;
- расписание;
- электронный журнал;
- посещаемость;
- оценки;
- отчеты;
- интеграции с Moodle, LDAP/AD, ФРДО и ФИС ГИА.

## Стек

Backend:
- Laravel 12
- PHP 8.4
- REST API
- PostgreSQL 17

Frontend:
- Vue 3
- Vite
- Pinia
- Tailwind CSS

Инфраструктура:
- Docker
- Nginx
- Ubuntu Server 24.04
- Hyper-V VM

## Архитектурные правила

1. Не писать весь проект одним файлом.
2. Использовать модульную структуру.
3. Использовать миграции Laravel.
4. Использовать Eloquent-модели и связи.
5. Использовать Form Request Validation.
6. Использовать Policy/Gate для прав доступа.
7. Использовать Service Layer для бизнес-логики.
8. Использовать DTO там, где есть сложные входные данные.
9. Использовать Resource-классы для API-ответов.
10. Все изменения делать маленькими шагами.
11. После каждого этапа кратко объяснять, что изменено.

## Важное

Проект предназначен для российского СПО/колледжа искусств. В будущем нужны интеграции с:

- Moodle;
- ФРДО;
- ФИС ГИА;
- Active Directory;
- Telegram/email-уведомлениями.

Сейчас делать только MVP.

## Политика рабочих путей

Разрешенные рабочие каталоги:

- Windows: `C:\!Projects\CollegePortal`
- Linux DEV: `/srv/college-dev`

Если нужен отдельный Git worktree на Windows, создавать его только внутри:

```text
C:\!Projects\CollegePortal\.worktrees\<branch>
```

Запрещено использовать устаревшую Windows-копию проекта с нижним регистром в имени каталога, внешние каталоги worktree рядом с проектом, временные каталоги старой копии, а также создавать worktree рядом с проектом.

## Background Agent Convention

The named background agents Hubble, Mencius, Boole, Erdos, Bohr and Pasteur are documented in [docs/AGENTS.md](docs/AGENTS.md). These names identify Codex background-agent instances in the CollegePortal workflow. Their documented roles are a project convention, not built-in Codex system specializations, and any actual run is governed by the prompt assigned to that agent.

## Session Handoff

Before ending a session, changing to an independent task, or when context is nearing its practical limit, update [Active Work](docs/ACTIVE_WORK.md).

The handoff must include the active branch, local and DEV HEAD, uncommitted changes, verified checks, blockers, exact next actions, and whether DEV/PROD changed. Run `git status --short`, `git diff --check`, and `git log --oneline -10` before writing the handoff.

After completing a large context-intensive task, offer the user a clean new chat for the next independent task. The agent cannot programmatically detect the model context limit or create a chat, so this is a mandatory proactive workflow step rather than a runtime automation.
