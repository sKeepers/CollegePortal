# Окружения CollegePortal

Документ фиксирует назначение окружений CollegePortal и визуальные признаки, которые помогают не перепутать DEV, TEST и PROD.

## Общий принцип

Frontend определяет текущее окружение по переменной:

```env
VITE_APP_ENV=development
```

Поддерживаемые значения:

- `development` — DEV;
- `test` — TEST;
- `production` — PROD.

Если переменная не задана или задана неизвестным значением, интерфейс считает окружение production. Это сделано консервативно: неизвестный стенд не должен выглядеть как безопасная песочница.

## Визуальная индикация

В верхней панели отображается бейдж окружения:

- DEV — желтый;
- TEST — синий;
- PROD — зеленый.

Сверху приложения отображается тонкая линия высотой 4 px:

- DEV — желтая;
- TEST — синяя;
- PROD — зеленая.

Title браузера:

- production: `CollegePortal`;
- development: `[DEV] CollegePortal`;
- test: `[TEST] CollegePortal`.

Tooltip бейджа:

- DEV: `Development Environment`;
- TEST: `Test Environment`;
- PROD: `Production Environment`.

## DEV

Назначение:

- ежедневная разработка;
- проверка новых GUI-модулей;
- экспериментальные изменения до переноса в PROD.

Текущий путь:

```text
/srv/college-dev
```

Порты:

```text
Frontend DEV:   5174
Backend/API DEV: 8001
PostgreSQL DEV: 5433
```

Рекомендуемая переменная frontend:

```env
VITE_APP_ENV=development
```

## TEST

Назначение:

- будущий промежуточный стенд для приемочной проверки;
- демонстрация изменений пользователям до PROD;
- проверка миграций, интеграций и сценариев без риска для PROD.

Сейчас отдельный TEST-стенд не развернут. При добавлении TEST нужно выделить отдельные контейнеры, базу данных, `.env` и порты, чтобы он не использовал DEV или PROD данные.

Рекомендуемая переменная frontend:

```env
VITE_APP_ENV=test
```

## PROD

Назначение:

- рабочая версия портала;
- реальные пользователи и данные;
- изменения только после проверки в DEV и подготовки backup.

Текущий путь:

```text
/home/andale/college_portal
```

Порты текущего рабочего стенда:

```text
Frontend PROD:   5173
Backend/API PROD: 8080
PostgreSQL PROD: 5432
```

Рекомендуемая переменная frontend:

```env
VITE_APP_ENV=production
```

## Порядок работы

1. Разработка выполняется только в DEV.
2. После задачи выполняются проверки и Git checkpoint.
3. PROD не изменяется без отдельного подтверждения.
4. Перед переносом DEV → PROD нужно выполнить backup по `docs/DEPLOYMENT.md`.
5. После деплоя в PROD нужно проверить, что бейдж показывает `PROD`, а title браузера равен `CollegePortal`.

## Что нельзя делать

- Не запускать DEV с PROD-базой.
- Не запускать TEST с PROD-базой.
- Не скрывать бейдж окружения в рабочем интерфейсе.
- Не считать окружение безопасным, если `VITE_APP_ENV` не задан.

## REPO-SYNC-001: инвентаризация копий

GitHub является canonical repository:

```text
https://github.com/sKeepers/CollegePortal
```

Linux DEV:

- host: `moodle`;
- IP: `192.168.34.104`;
- OS: Ubuntu 24.04.3 LTS;
- path: `/srv/college-dev`;
- branch после merge PR #8: `develop`;
- HEAD после merge PR #8: `a64b341`;
- remote: `https://github.com/sKeepers/CollegePortal.git`;

Infrastructure clarification INFRA-ACCESS-001.1:

- `192.168.34.104` / hostname `moodle` is the current factual CollegePortal DEV host and contains the active `/srv/college-dev` repository;
- `192.168.34.114` is a separate Linux server; SSH is reachable, but key-based login for `andale` is not configured yet, so its role requires clarification before it can be treated as DEV;
- Moodle service and CollegePortal DEV currently share the same host; do not move the project to another server without a separate infrastructure decision.
- state: clean, ahead 0 / behind 0.

UAT:

- host/IP: `192.168.34.17`;
- path: `/opt/college-portal`;
- обновляется только через installer/update script и release archive;
- `git pull` на UAT в рамках repository sync не выполняется.

ViPNet Gateway:

- host/IP: `192.168.34.223`;
- OS: Windows 7;
- полный CollegePortal туда не клонируется;
- устанавливается только CollegePortal Gateway в `C:\CollegePortalGateway` через отдельный ZIP release.

PROD:

- в REPO-SYNC-001 не инвентаризировался;
- credentials не искать, подключение не выполнять;
- статус: untouched / unknown until dedicated deployment inventory.

Windows local development copy:

- возможный путь: `C:\!Projects\college_portal`;
- подтверждается и синхронизируется вручную через `scripts/repository/sync-collegeportal-windows.ps1`;
- скрипт отказывается от pull при dirty working tree.

Не включать в документы пароли, токены, private config и другие секреты.

## Integration Gateway Update

Linux DEV hostname: `moodle`.
Primary IPv4 for LAN access: `192.168.34.104` on `eth0`.
Default gateway: `192.168.34.1`.
Route to ViPNet PC `192.168.34.223`: direct via `eth0` from `192.168.34.104`.
Docker bridge addresses `172.17.0.1`, `172.18.0.1` and `172.19.0.1` are not server LAN addresses and must not be used in Gateway allowlists.

ViPNet workstation installs only CollegePortal Gateway in `C:\CollegePortalGateway`; do not clone the full repository there. UAT and PROD are not changed by Integration Hub tasks.
