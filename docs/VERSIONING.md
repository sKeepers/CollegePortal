# Versioning CollegePortal

## Назначение

CollegePortal использует единый публичный файл версии для отображения информации о текущей сборке в интерфейсе и для быстрой диагностики стенда.

Файл версии находится в:

```text
frontend/public/version.json
```

Он попадает в frontend build как статический файл и читается через `frontend/src/services/versionService.js`.

## Текущая схема версии

Актуальная публичная RC-версия CollegePortal:

```text
0.8.0-rc2
```

GitHub release tag:

```text
v0.8.0-rc2
```

Формат:

```text
major.minor.patch-suffix
```

- `major` — крупная стабильная версия платформы.
- `minor` — функциональный релиз или milestone.
- `patch` — исправления без изменения набора возможностей.
- `suffix` — стадия сборки: `dev`, `test`, `rc`, без suffix для production-ready релиза.

## Структура version.json

```json
{
  "name": "CollegePortal",
  "version": "0.8.0-rc2",
  "release": "v0.8.0-rc2",
  "build": "git-short-hash",
  "gitCommit": "git-full-hash",
  "buildDate": "YYYY-MM-DD",
  "environment": "development",
  "frontendStack": "Vue 3 + Quasar + Vite",
  "backendStack": "Laravel 12 + PHP 8.4",
  "apiVersion": "v1"
}
```

Поля:

- `name` — название системы.
- `version` — техническая версия.
- `release` — человекочитаемое имя релиза.
- `build` — короткий Git hash сборки.
- `gitCommit` — полный Git hash сборки.
- `buildDate` — дата подготовки сборки.
- `environment` — окружение сборки: `development`, `test`, `production`.
- `frontendStack`, `backendStack`, `apiVersion` — диагностическая информация для окна `О системе`.

## Отображение в интерфейсе

В нижней части левого меню отображается компактный блок:

```text
CollegePortal
v0.8.0-rc2
Build: <hash>
DEV
```

При клике открывается окно `О системе`, где показаны:

- название;
- версия;
- релиз;
- build;
- дата сборки;
- окружение;
- frontend stack: Vue + Quasar;
- backend stack: Laravel;
- API: v1.

Фактическая метка окружения в интерфейсе берется из `frontend/src/services/environmentService.js`, чтобы DEV/TEST/PROD соответствовали текущему `VITE_APP_ENV`.

## Как обновлять version.json

`frontend/public/version.json` генерируется перед frontend build командой:

```bash
npm run build
```

Генератор находится в:

```text
frontend/scripts/generate-version.mjs
```

Он берет значения из переменных окружения:

```text
APP_VERSION=0.8.0-rc2
APP_RELEASE=v0.8.0-rc2
VITE_APP_VERSION=0.8.0-rc2
VITE_APP_RELEASE=v0.8.0-rc2
VITE_BUILD_COMMIT=<short-hash>
VITE_BUILD_FULL_COMMIT=<full-hash>
VITE_BUILD_DATE=YYYY-MM-DD
```

Если переменные не заданы, используются безопасные значения актуального RC:

```text
version=0.8.0-rc2
release=v0.8.0-rc2
```

Для точной сборки стенда рекомендуется передавать текущий Git hash:

```bash
git rev-parse --short HEAD
git rev-parse HEAD
```

Для будущего production deployment рекомендуется автоматизировать генерацию `version.json` в deploy-скрипте, чтобы `build` точно соответствовал выкладываемому commit.

## Правила будущих версий

### 0.8

Версия `0.8.x` предназначена для Private Release Candidate и UAT-стабилизации: приемная комиссия, импорт, права доступа, установщик, демонстрационный контур и эксплуатационные доработки.

### 0.9

Версия `0.9.x` предназначена для предрелизной стабилизации: security hardening, backup/rollback rehearsal, проверка производительности, пользовательская приемка и подготовка к production.

### 1.0

Версия `1.0.0` допускается только после:

- успешной пилотной эксплуатации;
- подтвержденного backup/rollback процесса;
- проверенного production security checklist;
- ревизии пользователей и ролей;
- отсутствия блокирующих UAT-замечаний;
- стабильных `php artisan test` и `npm run build`.

## Ограничения текущего этапа

`version.json` является статическим frontend-файлом. Он не заменяет backend health-check и не является источником миграционного состояния БД. Для production нужно дополнительно проверять backend commit, миграции, Docker images и состояние окружения.
