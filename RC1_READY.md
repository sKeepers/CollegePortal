# RC1 Ready: Admissions Foundation

## Статус

Admissions Foundation подготовлен как первый release candidate для переноса в продуктивный контур после успешной сборки, миграций и ручного UAT на DEV/UAT. Новых функций в RC1-проходе не добавлялось; изменения ограничены стабилизацией мастера, duplicate-check, документацией и release report.

## Реализованные функции

- Reference Data приемной комиссии: статусы, типы документов, основания, источники, справочники choices.
- Person и Applicant foundation: создание, редактирование, просмотр, безопасная связь Applicant с Person.
- Проверка дублей Person по СНИЛС, email, телефону, паспортному документу и ФИО + дате рождения.
- Заявления foundation: список, фильтры, карточка, создание черновика, редактирование черновика, регистрация.
- Program Choices: несколько образовательных программ с приоритетами, изменение порядка, архивирование выбора.
- Документы Applicant: СНИЛС, документы личности, документы об образовании, version chain, private files.
- Document Set заявления: фиксация версий документов на заявлении.
- Readiness и FIS Readiness: blockers, warnings, внутренние проверки комплектности и mapping-ready статусы.
- Workspace `/admissions/foundation`: мастер заявления, карточки Person/Applicant, документы, файлы, choices, readiness, FIS, история Audit.
- RBAC и Audit для реализованных backend actions.

## Исправленные замечания RC1

1. Мастер нового заявления теперь сбрасывает все поля при повторном открытии и не переносит данные прошлого черновика.
2. Duplicate-check учитывает СНИЛС, введенный на шаге паспорта, даже если поле Person.СНИЛС на первом шаге не заполнено.
3. Документация RBAC обновлена: `admissions.applicant.create/update/archive` больше не описаны как будущие не реализованные permissions.

## Release Report

| Показатель | Значение |
| --- | ---: |
| Backend endpoints Admissions/People foundation | 42 |
| Frontend страниц в RC1-scope | 2 |
| Shared UI/components, используемые workspace | 10 |
| Связанных frontend files Admissions/Person | 13 |
| Backend test-файлов Admissions/Person scope | 18 |
| Исправленных RC1-замечаний | 3 |

Frontend страницы RC1-scope:

- `/admissions/foundation`;
- `/people`.

## Security Review

- QR, ФИС production и внешние интеграции этим RC не изменялись.
- Duplicate-check не выполняет автоматический merge.
- `people/merge` остается stop-gate и возвращает `501 merge_not_supported`.
- Скачивание private-файлов остается за `admissions.document.download_sensitive`.
- Архивирование Applicant выполняется через `archived_at`, физическое удаление не реализовано.
- Registered-заявления остаются read-only для реквизитов заявления, choices, документов и файлов.
- Audit пишет действия foundation-сервисов без хранения raw private files.

## Известные ограничения

- Локальная Windows shell Codex не видит `node`, `npm`, `php` и `docker`; поэтому `npm run build`, `php artisan test`, миграции и docker compose должны быть подтверждены в CI или на DEV с установленным toolchain.
- Автоматизированных frontend tests для `/admissions/foundation` в `frontend/package.json` пока нет.
- Отдельного `lint` script в `frontend/package.json` нет.
- Merge дублей Person не реализован намеренно.
- Форма обучения и источник финансирования в choices отображаются, если уже заданы backend, но выбор этих справочников остается отдельным slice.
- FIS XML/package send не входит в RC1.
- Конкурс, приказы, зачисление и личный кабинет абитуриента остаются следующими этапами.

## Что войдет в RC2

- Автоматизированный frontend/e2e smoke для `/admissions/foundation`.
- Расширение choices по форме обучения и финансированию после финализации справочников.
- Безопасный merge Person как отдельный workflow с preview/audit.
- Конкурсный список и enrollment preview.
- Подготовка FIS export/readiness к реальному пакету после подтвержденного XML-контракта.
- UAT checklist для ролей `admin`, `admission`, `director`, `study`.

## Go/No-Go перед PROD

- `npm run build` должен пройти в CI или DEV.
- `php artisan test` должен пройти в CI или DEV.
- Миграции должны применяться на staging/UAT без destructive changes.
- Ручной smoke `/admissions/foundation` должен подтвердить создание Person, Applicant, заявления, документов, файлов, choices и регистрацию.
- Проверить, что PROD backup выполнен перед deploy.

## Выполненные проверки в текущей среде

| Проверка | Результат |
| --- | --- |
| `git diff --check` | passed |
| Static route/API audit | passed, найдено 42 endpoint в Admissions/People foundation scope |
| Static RBAC audit | fixed stale Applicant permissions note |
| `npm run build` | blocked: `npm` не найден в текущей shell |
| production build | blocked: `npm` не найден в текущей shell |
| frontend lint | not available: script отсутствует |
| `php artisan test` | blocked: `php` не найден в текущей shell |
| `php artisan migrate --pretend` | blocked: `php` не найден в текущей shell |
| `docker compose config` | blocked: `docker` не найден в текущей shell |
