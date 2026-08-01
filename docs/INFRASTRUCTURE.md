# CollegePortal Infrastructure

Документ фиксирует фактическую инфраструктуру CollegePortal без паролей, ключей и токенов.

## Узлы

| Узел | IP | Назначение |
|---|---:|---|
| Windows 11 workstation | `192.168.34.212` | Codex, VS Code, Git, сборка Windows Gateway |
| CollegePortal DEV / hostname `moodle` | `192.168.34.104` | Текущая рабочая Linux-среда разработки, Docker, `/srv/college-dev` |
| Candidate Linux DEV | `192.168.34.114` | SSH reachable, назначение требует уточнения, key login для `andale` пока не настроен |
| UAT | `192.168.34.17` | Пользовательское тестирование, обновление только release/install flow |
| Zabbix | `192.168.34.105` | Мониторинг, не использовать для разработки CollegePortal |
| ViPNet Gateway host | `192.168.34.223` | Windows 7 SP1, только `C:\CollegePortalGateway` |

## Текущий DEV

Фактический DEV подтвержден на `192.168.34.104`:

- hostname: `moodle`;
- path: `/srv/college-dev`;
- remote: `https://github.com/sKeepers/CollegePortal.git`;
- Docker установлен и используется для backend/frontend/postgres/nginx.

`192.168.34.114` не считать основным DEV до отдельной инвентаризации и настройки SSH key access.

## Правила безопасности

- PROD не трогать без отдельной задачи.
- UAT не обновлять через `git pull`.
- Не хранить пароли, private keys, токены и реальные базы в Git.
- На ViPNet-ПК не клонировать полный CollegePortal.
