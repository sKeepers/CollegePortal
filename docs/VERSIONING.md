# Versioning CollegePortal

## Назначение

CollegePortal использует единый публичный файл версии для отображения информации о текущей сборке в интерфейсе и для быстрой диагностики стенда.

Файл версии находится в:

```text
frontend/public/version.json
```

Он попадает в frontend build как статический файл и читается через `frontend/src/services/versionService.js`.

## Текущая схема версии

Для Release 0.7 используется версия:

```text
0.7.0-dev
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
  "version": "0.7.0-dev",
  "release": "Release 0.7",
  "build": "git-short-hash",
  "buildDate": "YYYY-MM-DD",
  "environment": "development"
}
```

Поля:

- `name` — название системы.
- `version` — техническая версия.
- `release` — человекочитаемое имя релиза.
- `build` — короткий Git hash сборки.
- `buildDate` — дата подготовки сборки.
- `environment` — окружение сборки: `development`, `test`, `production`.

## Отображение в интерфейсе

В нижней части левого меню отображается компактный блок:

```text
CollegePortal
v0.7.0-dev
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

Перед сборкой релиза обновить:

1. `version`.
2. `release`.
3. `build` текущим коротким hash:

```bash
git rev-parse --short HEAD
```

4. `buildDate` текущей датой:

```bash
date +%F
```

5. `environment` целевым окружением.

Для будущего production deployment рекомендуется автоматизировать генерацию `version.json` в deploy-скрипте, чтобы `build` точно соответствовал выкладываемому commit.

## Правила будущих версий

### 0.8

Версия `0.8.x` предназначена для следующего расширения после пилотной загрузки реальных данных: стабилизация импорта, UAT-исправления, права доступа и эксплуатационные доработки.

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
