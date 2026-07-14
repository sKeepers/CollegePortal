# CollegePortal: правила работы Codex

CollegePortal — модульная информационная система российского колледжа искусств. Пользовательские сообщения, интерфейс и проектная документация пишутся на русском языке; технические идентификаторы могут быть на английском.

## Окружения

- Windows 11 (`192.168.34.212`) — рабочая станция Codex, VS Code, Git и сборка Windows Gateway.
- CollegePortal DEV — адрес, hostname и путь брать только из актуального `docs/ENVIRONMENTS.md`. Здесь выполняются миграции, Laravel tests, frontend build и Docker-проверки.
- UAT (`192.168.34.17`) — только установка release-архива и ролевое тестирование. Обновлять через installer/update flow, не через `git pull`.
- Moodle (`192.168.34.104`) — отдельный сервис, если `docs/ENVIRONMENTS.md` не подтверждает его как текущий DEV.
- Zabbix (`192.168.34.105`) — только мониторинг.
- ViPNet-ПК (`192.168.34.223`) — только CollegePortal Gateway и доступ к ФИС.
- PROD — не трогать без отдельного явного задания.

## Обязательный процесс

1. До изменений прочитать `PROJECT_CONTEXT.md`, `ROADMAP.md`, `TASKS.md` и ближайший локальный `AGENTS.md`.
2. Одна задача выполняется в одной ветке; параллельная задача — в отдельном worktree.
3. Сначала обновлять `develop`. `main` — только после отдельного разрешения и green CI.
4. Делать небольшие совместимые изменения. Breaking change допустим только по явному требованию.
5. Не использовать `git reset --hard`, `git clean -fd`, force push и другие разрушительные команды.
6. Не ослаблять и не пропускать тесты ради green status.
7. Не считать функцию готовой без фактической проверки.
8. При stop-gate остановиться, зафиксировать доказательства и не создавать фиктивный commit о завершении.

## Данные и секреты

- Не публиковать ПДн, реальные импорты/экспорты, дампы, backups и generated documents.
- Не добавлять в Git пароли, токены, сертификаты, private keys, private storage и runtime-конфигурацию.
- В логах, traces, screenshots и отчетах обезличивать данные и секреты.

## Типовые quality gates

- migration smoke на чистой PostgreSQL, если затронута БД;
- targeted tests и полный backend test suite;
- frontend build;
- `git diff --check`;
- secret scan и forbidden-file check;
- route/API smoke;
- browser UAT для UI-задач;
- актуализация документации;
- green CI.

## Итоговый отчет

Указать: среду, ветку, исходный и итоговый commit, причину и файлы изменений, миграции, tests/assertions, frontend build, browser checks, security checks, ограничения, PR, CI и commit hash. Не включать credentials и ПДн.
