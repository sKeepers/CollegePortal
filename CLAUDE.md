# CollegePortal

Информационная система колледжа искусств: приёмная комиссия, контингент, учебные планы, нагрузка, расписание, электронный журнал, кадры, проходная по QR, отчёты, подготовка данных для ФИС ГИА и ФРДО.

Стек: Laravel 12 / PHP 8.4 / PostgreSQL 17 / Redis на бэкенде, Vue 3 + Vite + Pinia + Quasar на фронтенде, Docker Compose и Nginx в развёртывании.

## Язык

Ответы пользователю, планы, отчёты, документация и любой текст интерфейса — на русском. Английский только для неизменяемых технических идентификаторов: команды, пути, URL, endpoints, имена классов и переменных, ветки и сообщения коммитов.

## Главное, что нужно знать до начала работы

**На рабочей станции Windows нет инструментов разработки.** Ни `php`, ни `node`, ни `npm`, ни `docker`, ни `composer`. Есть только `git`, `ssh` и VS Code. Не пытайтесь запускать здесь тесты или сборку — это не поломка, так устроено рабочее место.

Вся сборка и все проверки выполняются **на DEV-сервере по SSH**.

## Где что работает

| Адрес | Роль | Как обращаться |
| --- | --- | --- |
| `C:\!Projects\CollegePortal` | рабочая копия, только редактирование | — |
| `192.168.34.114` | DEV: сборка, тесты, проверка | `ssh andale@192.168.34.114`, checkout в `/home/andale/CollegePortal` |
| `192.168.34.17` | PROD | `ssh andale@192.168.34.17`, установка в `/opt/college-portal` |
| `192.168.34.1` | Mikrotik, учётная запись только на чтение | `ssh ai_read@192.168.34.1` |
| `192.168.34.223` | ViPNet-ПК Windows 7, шлюз ФИС ГИА | SSH |

Портал наружу: `https://portal.skki.ru` ведёт на PROD. DEV снаружи — `https://84.54.208.134:5443`, внутри — `https://192.168.34.114:5443`.

Учётные данные серверов и ключи лежат вне репозитория, в `.local/`. Никогда не коммитьте их и не приводите в документации, отчётах и сообщениях коммитов.

На PROD заведена служебная учётная запись `cp-agent` для обслуживания портала: вход только по ключу `.local/ssh/cp-agent`, `sudo` без пароля разрешён ровно на четыре скрипта установщика — `update.sh`, `check.sh`, `backup.sh`, `restore.sh`. Ни docker, ни чтение `.env` она не получает; для этого по-прежнему нужен `andale` и пароль. Скрипт создания — `.local/ops/provision-cp-agent.sh`, откат — `userdel -r cp-agent` и удаление `/etc/sudoers.d/60-cp-agent`.

## Как запускать проверки

Всё выполняется в контейнере на DEV. Рабочее дерево DEV трогать не нужно — создавайте отдельный worktree.

```bash
# на DEV
cd /home/andale/CollegePortal
git worktree add -b <branch> /tmp/work origin/develop
cp backend/.env /tmp/work/backend/.env
mkdir -p /tmp/work/backend/storage/framework/{cache,sessions,views} /tmp/work/backend/storage/logs /tmp/work/backend/bootstrap/cache
chmod -R 777 /tmp/work/backend/storage /tmp/work/backend/bootstrap/cache

V1="/tmp/work/backend:/var/www/html"
V2="/home/andale/CollegePortal/backend/vendor:/var/www/html/vendor:ro"
docker run --rm -v "$V1" -v "$V2" -w /var/www/html collegeportal-backend php artisan test
```

Образ бэкенда называется `collegeportal-backend`. Зависимости `vendor` монтируются из основного checkout — в worktree их нет.

Прогон на PostgreSQL, который ближе к бою, чем SQLite по умолчанию:

```bash
docker exec college_dev_postgres psql -U college_user -d college_portal -c "CREATE DATABASE college_portal_test"
# в .env worktree выставить DB_CONNECTION=pgsql, DB_HOST=postgres, DB_DATABASE=college_portal_test
docker run --rm --network collegeportal_default -v "$V1" -v "$V2" -w /var/www/html collegeportal-backend \
  php artisan test -c phpunit.pgsql.xml
```

Сборка фронтенда:

```bash
docker run --rm -v "/tmp/work/frontend:/app" -w /app node:22-alpine npm run build
```

Монтировать `node_modules` только на чтение нельзя: Vite пишет временные файлы. Копируйте каталог либо ставьте зависимости заново.

## Локальная рабочая копия всегда актуальна

**Владелец читает код и документацию в локальной копии, а не на серверах.** Поэтому она обязана быть на текущем стволе.

`C:\!Projects\CollegePortal` стоит на ветке `develop`, которая отслеживает `dev/develop` — remote `dev` подключён к DEV по SSH:

```
dev  ssh://andale@192.168.34.114/home/andale/CollegePortal
```

Через `origin` обновляться нельзя: он подключён по HTTPS и потребует интерактивной авторизации. Через `dev` работает по ключу, без вопросов.

Обновление локальной копии — одна команда:

```bash
git fetch dev && git merge --ff-only dev/develop
```

Настроено `pull.ff only`, поэтому случайный merge-коммит в локальной копии не появится.

**Правило сессии: закончив работу и отправив коммиты, обновите локальную копию этой командой.** Иначе владелец читает старый код, а найденные им «ошибки» окажутся уже исправленными.

Ветка `dev-line` — историческая, для работы не используется. Состояние её рабочего дерева на 08.08.2026 сохранено в `safety/dev-line-local-20260808`; проверено, что уникального кода в нём не было.

## Ветки и релизы

Ствол — `develop`. CI запускается на `develop`, `main` и ветках `feature/**`, `fix/**`, `sync/**`, `rescue/**`, `ci/**`; проверяет политику путей, тесты на SQLite и на PostgreSQL 17, сборку фронтенда и поиск секретов.

Push с рабочей станции невозможен: удалённый репозиторий подключён по HTTPS и потребует интерактивной авторизации. **Коммиты и push делаются с DEV**, где настроен доступ по SSH-ключу.

Релиз собирается только на DEV и только при чистом рабочем дереве:

```bash
bash scripts/release/build-release.sh
```

Сначала поднимите версию в `installer/VERSION`, затем соберите, затем ставьте тег — **тег на коммит сборки, а не заранее**. Обновление PROD:

```bash
# с рабочей станции, через служебную учётную запись
scp releases/college-portal-<версия>.tar.gz cp-agent@192.168.34.17:/home/cp-agent/
ssh -i .local/ssh/cp-agent cp-agent@192.168.34.17 \
  "sudo -n /opt/college-portal/installer/update.sh /home/cp-agent/college-portal-<версия>.tar.gz"
```

Скрипт сам делает резервную копию, включает режим обслуживания, мигрирует и проверяет здоровье. При неудачной проверке он **запрашивает подтверждение отката интерактивно** — в неинтерактивной сессии это не сработает, и скрипт просто завершится с ошибкой. Откат тогда делается отдельным вызовом `installer/restore.sh` с путём из строки `Backup before update:` в выводе. Если обновление запускает человек, лучше делать это в `tmux`: сессия переживёт обрыв связи и вопрос об откате дойдёт до терминала.

## Политика путей

Разрешены только `C:\!Projects\CollegePortal` на Windows и `/srv/college-dev` либо `/home/andale/CollegePortal` на Linux. Git worktree — исключительно внутри `.worktrees/<branch>`. Проверяется в CI через `scripts/repository/assert-path-policy.sh`.

## Грабли, на которые уже наступали

- `frontend/public/version.json` генерируется при каждой сборке и не отслеживается в git. Не возвращайте его под контроль версий: сборка релиза требует чистого дерева и перестанет работать дважды подряд.
- `frontend/Dockerfile.release` сохраняет `version.json` из архива поверх сгенерированного. Без этого релиз представляется как `dev-unknown` в окружении `development`.
- `.env` на PROD принадлежит root. Команды, которые его читают, целиком заворачивайте в `sudo bash -c '...'`.
- Резервные копии PROD лежат в `/var/backups/college-portal`, а не в `/srv/backups`, как написано в README.
- В `EnsurePermission` права выводятся из префикса URL по таблице, и при отсутствии совпадения молча возвращается `reference.manage`. Новый маршрут, не внесённый в таблицу, даст законному пользователю необъяснимое «нет прав». Это задача `ARCH-001`.
- Отсутствие связанного профиля не должно давать 403. Пользователь с правом, но без профиля, обязан получать пустой список: так сделано в `DigitalIdentityController` и `TeachingLoadController`.
- Ветка `feature/access-control-foundation` содержит вторую, невлитую реализацию контроля доступа с собственными таблицами и другим форматом токена. Не переносите оттуда патчи механически. Её проектные документы уже перенесены в `docs/ACCESS_CONTROL_*.md`, каждый помечен происхождением, а расхождения с действующей реализацией сведены в [docs/ACCESS_CONTROL_STATUS.md](docs/ACCESS_CONTROL_STATUS.md).

## Архитектурные правила

Модульная структура, миграции Laravel, Eloquent-модели и связи, Form Request Validation, Policy и Gate для доступа, сервисный слой для бизнес-логики, DTO для сложных входных данных, Resource-классы для ответов API. Изменения — небольшими шагами, после каждого этапа краткое объяснение, что изменено.

## Карта документации

- [Индекс документации](docs/README.md)
- [Состояние проекта](docs/PROJECT_STATUS.md)
- [Текущая работа и передача сессии](docs/ACTIVE_WORK.md)
- [Дорожная карта](ROADMAP.md) и [Задачи](TASKS.md)
- [План авторизации и мобильного контура](docs/AUTH_AND_MOBILE_PLAN.md)
- [TLS-сертификат портала](docs/TLS_CERTIFICATE.md)
- [Проверка внешнего анализа и задачи SEC](docs/EXTERNAL_ANALYSIS_VALIDATION_2026-08-03.md)

Перед завершением сессии или переходом к другой задаче обновляйте [docs/ACTIVE_WORK.md](docs/ACTIVE_WORK.md): ветка, HEAD локально и на DEV, незакоммиченное, выполненные проверки, блокеры, точные следующие шаги и изменялись ли DEV и PROD.

## Что запрещено без отдельного поручения

Разворачивать на PROD, коммитить чужие незавершённые изменения, публиковать реальные персональные данные, ключи и токены, откатывать чужую работу. Реальные ПДн не использовать нигде: для проверок есть демонстрационные и UAT-данные.
