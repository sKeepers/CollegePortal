# Текущая работа

## Назначение

Файл фиксирует состояние рабочей сессии CollegePortal. Это точка входа для нового чата, агента или инженера после прочтения `AGENTS.md`.

Обновлять перед окончанием сессии, переходом к существенно другой задаче и после каждого развёртывания на DEV.

## Обновлено

- Date: 2026-08-05
- Local worktree: `C:\!Projects\CollegePortal`
- DEV checkout: `/home/andale/CollegePortal`

## Git-состояние

- Active worktree branch: `feature/uat-002-1-final-stabilization`
- Local HEAD: `b67d118` (`DOCS: record account provisioning handoff`)
- Last deployed DEV checkpoint: `ddc788d0b` (`FEAT: tailor HR dashboard and access reports`)
- DEV branch: `feature/uat-002-1-final-stabilization`
- GitHub branch: `origin/feature/uat-002-1-final-stabilization` развёрнута на DEV до `3f4b237ef`.
- Локальная ветка `sync/sync-001-local` содержит self-scoped read-only просмотр нагрузки, улучшения журнала и сквозной workflow запроса редактирования поверх `3f4b237ef`.
- `SYNC-001` объединил GitHub `SEC-001` с DEV UAT-002.2 и был развёрнут на DEV.
- На DEV применена миграция `2026_07_30_010000_add_lookup_and_expiration_to_api_tokens`.
- Проверки после развёртывания: `php artisan test` — `345 passed (2204 assertions)`; `npm run build` завершилась успешно.
- На DEV развёрнуты карточка оценок и адаптация профилей устройств после успешной production-сборки.
- Legacy `ScheduleLesson` теперь открывает конкретный `JournalLesson` через `legacy_schedule_lesson_id`; создание идемпотентно, переносит тему занятия и формирует roster студентов.
- Проверки исправления: `JournalEngineApiTest` — `10 passed (64 assertions)`; `npm run build` завершилась успешно.
- Teacher journal: список студентов растягивает страницу, подсказки проходной показывают результат для каждого студента, недоступные административные быстрые действия скрыты, а `Мои занятия` показывает все занятия преподавателя.
- Последняя проверка: `JournalEngineApiTest` — `10 passed (67 assertions)`.
- Подписанный журнал показывает подтверждение перед подписью. Преподаватель может направить запрос редактирования, а пользователь с `journal.reopen` одобряет или отклоняет его; одобрение переоткрывает журнал и фиксируется в audit.
- Миграция DEV `2026_08_02_010000_create_journal_edit_requests_table` применена. `JournalEngineApiTest`: `11 passed (75 assertions)`.
- Pending запросы отображаются в журнале администратора и на admin dashboard. Обе точки открывают конкретный журнал для одобрения или отклонения, а после решения запрос исчезает из обоих списков. `JournalEngineApiTest`: `11 passed (81 assertions)`.
- Незакоммиченные изменения root worktree: текущий `docs/ACTIVE_WORK.md`, пользовательские `docs/UAT_002_REPORT.md`, `docs/EXTERNAL_ANALYSIS_VALIDATION_2026-08-03.md` и generated `frontend/public/version.json` / `integrations/collegeportal-gateway/src/CollegePortal.Gateway.Host/obj/`. Пользовательские файлы не изменялись этой сессией.
- DEV worktree: generated `frontend/public/version.json` после build и `.worktrees/`; они не включены в checkpoint. На DEV применены `794672b93` (ФИС hardening), `abe6ac6ac` (исправление fixture), `93d75882f`/`c27cbcd56` (автоматические metadata и русские auth-ошибки), `9566c6674` (регламент) и `fd78865c4` (явные DEV UAT-пользователи); фактический ФИС apply не выполнялся.
- DEV-проверки на `c27cbcd56`: `FisAdmissionsImportHandlerTest` — `8 passed (35 assertions)`; `AuthApiTest` — `7 passed (26 assertions)`; `php artisan test` — `361 passed (2300 assertions)`; `npm run build` — успешно.
- Dashboard DEV использует общий `presentVersionInfo` из `versionService` для widget «Система» и диалога «О системе». Browser smoke после hard refresh 2026-08-03 подтвержден: version, release, build, Git commit, date и environment совпадают и не содержат `unknown`. Автоматическая metadata подтверждена screenshot: `dev-c27cbcd561f1`, `DEV build c27cbcd561f1`, short/full Git hash и дата сборки.
- Login с неверной парой email/password возвращает русское сообщение `Неверный email или пароль.`. UAT director существует и активен; значение пароля со screenshot не соответствует его текущему паролю.
- UAT teacher теперь получает связанную цепочку `User -> Person -> Teacher` при `UatUserSeeder`. Страница нагрузки не отправляет защищенный запрос для несвязанного teacher-профиля и показывает нейтральное состояние вместо красной ошибки; `TeachingLoadApiTest` и `AuthApiTest` — `12 passed (56 assertions)`.
- Верхняя панель пользователей с `uat.manage` или `journal.reopen` получает polling-inbox каждые 30 секунд: badge, список и Quasar popup для новых feedback и pending запросов редактирования журнала. Источники остаются защищены существующими API/RBAC.
- Исправлены верхний header (показывает `college_short_name`) и admin inbox feedback (использует `GET /api/admin/uat/feedback`). DEV build успешен. Browser smoke inbox ожидает hard refresh: для двух pending feedback ожидаются badge `2`, список и popup при входе.
- Passwordless SSH подтвержден: `ssh collegeportal-dev` подключается к `andale@192.168.34.114` ключом без password/passphrase. Root worktree и DEV имеют независимые новые commits; для дальнейшей синхронизации использовать отдельный worktree, не перезаписывая пользовательские изменения root.
- Выполнена read-only сверка внешнего технического анализа от 30.07.2026. Добавлен `docs/EXTERNAL_ANALYSIS_VALIDATION_2026-08-03.md` с подтвержденными и устаревшими выводами, стоп-факторами PROD и независимыми промптами `SEC-002`--`OPS-001`; обновлён индекс `docs/README.md`. Код, конфигурация, DEV и PROD в этой проверке не изменялись. Локальные Laravel-тесты не запускались, так как `php` отсутствует в PATH; `git diff --check` не выявил ошибок (только предупреждения LF/CRLF для существующих документов).
- Реализованы цифровые пропуска сотрудников: `DigitalIdentity::ENTITY_EMPLOYEE`, единый `DigitalIdentityService`, явный `POST /api/employees/{employee}/digital-pass` с permission `hr.employees.digital_pass.issue`, выдаваемым ролям `admin` и `hr`. Выпуск не вызывается при создании Employee; в `/hr/employees` он требует отдельного подтвержденного действия. Реестр `/identity/digital-passes` содержит тип «Сотрудник» и список сотрудников. Audit `employee_digital_pass_issued` не содержит токен, email или телефон; QR остается динамическим техническим ASCII payload без ПДн. Добавлены проверки API/RBAC/audit/QR и регрессия отсутствия автовыпуска.
- Локальные проверки текущей задачи: `node --check src/stores/digitalPasses.js` и `node --check src/stores/hr.js` успешны; `git diff --check` успешен. `php` отсутствует в PATH, поэтому PHPUnit и PHP lint не выполнены. `npm run build` запустил `prebuild`, но остановился с `vite: command not found`; изменение generated `frontend/public/version.json` отменено. DEV и PROD не изменялись.
- 2026-08-03: на DEV host-сборка `npm --prefix ... run build` не прошла из-за root-owned `frontend/node_modules/.vite-temp`; права не изменялись, так как `sudo` требует пароль. Штатная сборка через `docker compose ... exec -T frontend npm run build` прошла успешно (`458 modules transformed`, `built in 8.58s`). Она подтвердила текущий DEV commit `b7e11883e`, но не локальную доработку пропусков сотрудников, которая не закоммичена и не развёрнута. `prebuild` обновил только generated `frontend/public/version.json` на DEV; `.worktrees/` уже был untracked. PROD не изменялся.
- 2026-08-03: локально добавлен фундамент учетных записей student/teacher/employee: nullable unique `users.username`, вход по `login` (email, username или телефон связанного Person/Student/Teacher), `AccountProvisioningService`, роль `employee` с `dashboard.view` и `view_own_data`. Сервис возвращает одноразовый `ProvisionedAccount` DTO с `User` и plaintext-паролем только вызывающему коду. Только universal import handlers students/teachers/employees поддерживают optional `auto_account`; при создании профиля сервис создает и связывает учетную запись, но import игнорирует DTO и не записывает plaintext в audit или `ImportJob`. Добавлен защищенный `users.manage` endpoint `POST /api/admin/users/provision`, принимающий `profile_type` и `profile_id`, который единственный возвращает credential card с login, password, name и role; audit фиксирует только профиль и роль. Добавлены `AuthApiTest`, `UniversalImportApiTest` и `AdminUserApiTest`. Локально `php` и Docker отсутствуют, поэтому PHPUnit/PHP lint не выполнены; `git diff --check` успешен. DEV и PROD не изменялись. Незакоммиченные изменения этой задачи: migration username, DTO, `AccountProvisioningService`, auth/admin/import handlers, `RoleSeeder` и test-файлы; параллельные изменения цифровых пропусков и frontend не изменялись этой задачей.
- 2026-08-03: локально доработан импорт `employees`: табельный номер и дата приема необязательны; при отсутствии табельного номера назначается `EMP-IMPORT-*`. Повторная строка определяется по табельному номеру либо точному ФИО; несколько совпадений ФИО приводят к ошибке, не к созданию дубля. Заголовки `Отделение` и `Активен` поддерживаются, значение `1` нормализуется в `active`; неизвестная должность создается при подтверждении импорта. Миграция `2026_08_03_000000_make_employee_hired_at_nullable.php` делает `employees.hired_at` nullable. `/people` теперь по умолчанию исключает студентов и включает профиль сотрудника, фильтр и переход в кадровую карточку. Добавлены сценарии `HrFoundationApiTest` и `PersonFoundationTest`. Локально `php` и Docker отсутствуют, `npm run build` остановился на `vite: command not found`; `git diff --check` успешен. DEV и PROD не изменялись. Рабочее дерево уже содержит параллельные незакоммиченные изменения цифровых пропусков, учетных записей и документации; не откатывать их.
- 2026-08-03: локально доработана карточка и импорт студентов. Миграция `2026_08_03_000003_add_student_profile_and_enrollment_fields.php` добавляет nullable СНИЛС, адрес, паспортные реквизиты и номер/дату приказа о зачислении. Универсальный импорт принимает ФИО, курс, специальность, форму обучения, дату рождения, приказ, адрес и необязательный СНИЛС; дубли определяются по СНИЛС, email и ФИО+дате рождения. CSV-импорт применяет те же идентификаторы. Карточка и форма показывают курс, форму обучения, специальность, приказ, адрес и паспорт; паспорт и СНИЛС выдаются API только при `students.update`. Клиентский словарь ошибок теперь переводит `group_id` и `hired_at` в «Группа» и «Дата приема». `node --check` для `api.js` и `students.js`, а также `git diff --check` успешны; PHP-тесты и Vite-build локально недоступны. Скриншоты относятся к DEV commit `b7e11883e`, куда эти локальные изменения не развёрнуты. DEV и PROD не изменялись.
- 2026-08-03: по явному запросу изменения студентов развёрнуты в DEV поверх `3827c2dac` без commit. Применена миграция `2026_08_03_000003_add_student_profile_and_enrollment_fields` (batch 4). DEV-проверки: `StudentApiTest` — `10 passed (45 assertions)`, `UniversalImportApiTest` — `13 passed (83 assertions)`, `docker compose exec -T frontend npm run build` — успешно (`458 modules transformed`, `built in 8.56s`), `/health/live` вернул `status=ok`. Во время развёртывания была кратковременная 500 из-за ошибочной нормализации CRLF на переданных файлах; исходники немедленно восстановлены, повторные PHP lint, тесты и health подтверждают исправное состояние. DEV содержит незакоммиченные изменения только целевых Student API/import/frontend-файлов, migration, generated `frontend/public/version.json` и прежний `.worktrees/`; `git diff --check` на DEV успешен. PROD не изменялся.
- 2026-08-03: на DEV в форме справочника «Должности» свободный ввод категории заменен на список: руководители, педагогические работники, административно-управленческий, учебно-вспомогательный и обслуживающий персонал. `docker compose exec -T frontend npm run build` успешна (`458 modules transformed`, `built in 8.13s`). DEV остается незакоммиченным; PROD не изменялся.
- 2026-08-03: в форме создания сотрудника на DEV добавлен выбор существующей личной карточки. При выборе ФИО, телефон, email и СНИЛС заполняются из `Person`; при пустом выборе ввод ФИО и контактов создает личную карточку существующей бизнес-логикой `HrService`. Подразделение и должность по-прежнему выбираются только из кадровых справочников, а таблица должностей показывает категорию. DEV build успешен (`458 modules transformed`, `built in 8.14s`), `/health/live` вернул `status=ok`; PROD не изменялся.
- 2026-08-03: по жалобе UAT исправлена обязательность даты приема на DEV во всех слоях: `EmployeeController` принимает nullable `hired_at`, `HrService` сохраняет `null`, migration `2026_08_03_000000_make_employee_hired_at_nullable` применена (batch 5). DEV-проверки: `HrFoundationApiTest` — `5 passed (35 assertions)`, PHP lint контроллера и сервиса успешен, `/health/live` вернул `status=ok`. PROD не изменялся.
- 2026-08-03: добавлен график сотрудника с кодами `weekday_0900_1800`, `weekday_0900_1700`, `shift_2_2_0800_2000`, `flexible`; он назначается только в кадровой карточке. Вход frontend изменен на телефон/email/логин. В `/admin/users` добавлено явное создание учётной записи по ID профиля и одноразовая карточка для печати с пятизначным паролем; пароль не хранится в UI после закрытия. Личная self-scoped страница сотрудника с редактированием Person и расчетом статистики по назначенному графику еще не реализована. `node --check src/stores/users.js` успешен, `git diff --check` успешен; DEV/PROD не изменялись.
- Commit `3ae495e` объединяет цифровые пропуска сотрудников, учетные записи и карточки выдачи, графики сотрудников, настройки HR и связанные доработки импорта/студентов. В commit не включены пользовательский `docs/UAT_002_REPORT.md`, внешний анализ, generated `frontend/public/version.json` и `obj/`. Проверки PHP не выполнялись локально из-за отсутствующего `php`; перед deploy нужно запустить целевые тесты в DEV-контейнере. DEV содержит отдельные незакоммиченные изменения и опережает local HEAD, поэтому deploy не начинать без безопасной синхронизации.
- 2026-08-05: DEV-незакоммиченные изменения сохранены checkpoint commit `0998966d4` (`WIP: preserve student and HR enhancements`), generated `frontend/public/version.json` и `.worktrees/` не включались. Локальные commits `3ae495e` и `b67d118` опубликованы в `origin/sync/account-schedules-local`. Попытка `cherry-pick 3ae495e b67d118` поверх DEV выявила конфликты в Employee/HR/import/tests/docs; она отменена через `git cherry-pick --abort`, DEV остался на `0998966d4` без потери изменений. Следующее действие: создать разрешенный отдельный worktree от DEV checkpoint, вручную объединить оба набора, выполнить tests/build, затем commit и deploy.
- 2026-08-05: DEV продолжил независимую синхронизацию до `6ff6a6658`; рабочая копия DEV чиста кроме generated `frontend/public/version.json` и `.worktrees/`. DEV содержит проверенные доработки Student/HR: nullable дата приема, карточка сотрудника с выбором Person, категории должностей списком, маршрут «Личная карточка» с query `selected`, а также карточка и импорт студентов. `git diff --check` успешен локально и на DEV. PROD не изменялся.
- 2026-08-05: на DEV устранены подтвержденные дубли `employees`: удалены поздние копии `id IN (3,4,6,7,8)`, сохранены первичные записи; повторная SQL-проверка дубликатов по `person_id` вернула 0 строк. `EmployeeImportHandler` теперь при пустом табельном номере ищет существующий Person по СНИЛС, email, телефону, ФИО и дате рождения, либо точному ФИО; неоднозначные совпадения отклоняются. Пустой `employee_number` нормализуется в `null`, поэтому генерируется корректный номер. Добавлен регрессионный сценарий импорта двух одинаковых строк без номера. DEV HEAD: `48cc39f997c3a69dec08bec58ddf3318971af5c4`; DEV-незакоммичены только `backend/app/Services/Import/EmployeeImportHandler.php`, `backend/tests/Feature/HrFoundationApiTest.php`, generated `frontend/public/version.json` и `.worktrees/`. Проверки DEV: `HrFoundationApiTest` 6/41, `UniversalImportApiTest` 15/96, `StudentApiTest` 10/45, `StudentCsvApiTest` 3/20, `TeacherCsvApiTest` 3/17, `GroupCsvApiTest` 3/18, `ClassroomCsvApiTest` 3/17, `SubjectCsvApiTest` 3/18, `FisAdmissionsImportHandlerTest` 8/35; `npm run build` и `/health/live` успешны. PROD не изменялся.
- 2026-08-05: integration worktree `sync/integrate-account-schedules` объединил DEV checkpoint `0998966d4` с account/digital-pass/schedule changes. На DEV применены commits `0688cf6ce`, `6ff6a6658`, `c43aa4c84`, `7c917c070`, `48cc39f99`, `ddc788d0b`; миграции username и work schedule применены. Проверки: `DigitalIdentityApiTest` 8 passed, `UniversalImportApiTest` 15 passed, `AuthApiTest` 8 passed, `RbacApiTest` 9 passed, frontend build 459 modules успешно. `hr` привязан к Person/Employee Власовой Елены Александровны (`person_id=2`, `employee_id=2`). Основной admin получил login `admin`; UAT admin -- `admin.uat`; добавлен UAT HR login `hr`/`demo12345`. Роль HR ограничена кадровым контуром, преподавателями, своим QR и отчётами проходной; dashboard показывает сотрудников, преподавателей, находящихся в здании и отказы проходной. Требуется logout/login и `Ctrl+F5` для обновления permissions в старой сессии HR. DEV имеет незакоммиченные `EmployeeImportHandler.php`, `HrFoundationApiTest.php`, generated `version.json` и `.worktrees/`; PROD не изменялся.
- 2026-08-05: в DEV frontend редактора недели `/schedule` добавлены 16 слотов расписания звонков из предоставленной таблицы: `0` (07:15–08:00) и `1`–`15` (08:00–20:15). Новое занятие и шаблон по умолчанию используют слот `1` (08:00–08:45). Существующие записи расписания не изменялись; frontend build прошел успешно. Изменение добавлено после DEV checkpoint `ddc788d0b`, поэтому перед новым deploy требуется включить его в согласованный следующий checkpoint.

## Доступ к DEV

- Browser portal: `https://84.54.208.134:5443`
- Internal DEV portal: `https://192.168.34.114:5443`
- Health check: `http://127.0.0.1:8001/health/live`
- Containers: `docker compose -f /home/andale/CollegePortal/docker-compose.yml`
- Never put passwords, tokens, private keys, or personal data in this file.

## Текущая задача

DEV и root worktree имеют разные цепочки commits: root `b67d118`, DEV `ddc788d0b`; актуальная интеграционная ветка -- `sync/integrate-account-schedules` (`26a7852` плюс последующие UAT/login commits). Перед новой разработкой создавать разрешенный worktree от нужной базы. PROD не изменялся.

GitHub Issues доступны на DEV только для чтения через `gh`. Текущий обзор: [GitHub Issue Review 2026-08-01](GITHUB_ISSUE_REVIEW_2026-08-01.md); не изменять Issues без явной задачи.

Принятые требования:

1. Вход: показ пароля, сохранение браузером и выбор постоянной или сессионной авторизации.
2. Преподаватель: ограниченный собственными занятиями журнал с посещаемостью и оценками, без редактора расписания.
3. Студент: только личное расписание, режимы недели и месяца, детальные оценки без рабочего пространства журнала преподавателя.
4. Проходная: компактный сканер для телефона с низкой нагрузкой.
5. Адаптивная маршрутизация по ролям для телефона, планшета, HD, FullHD и широких desktop-экранов.

## Проверенные checkpoint

- `328572e`: динамический QR, защита от повторного использования, ограничение журнала студента.
- `c3c2a6d`: доступ студента к расписанию и мобильная навигация по датам.
- `88b1f83`: компактное рабочее пространство мобильного сканера.
- `86108ff`: личный QR-пропуск с обратным отсчётом.

## Следующие действия

1. Войти под UAT-учетными записями из `docs/TEST_USERS.md` и повторить сквозной UAT запроса редактирования: teacher отправляет запрос; admin видит его на `/dashboard` и `/journal`, открывает занятие и принимает решение.
2. Подтвердить, что после одобрения журнал переоткрыт, а pending запись исчезла с dashboard и из журнала администратора.
3. При необходимости перенести DEV commits `794672b93..fd78865c4` в локальный Git history отдельной синхронизационной задачей; root worktree не перезаписывать.
4. Для отдельной security-сессии использовать `docs/EXTERNAL_ANALYSIS_VALIDATION_2026-08-03.md`; начать с архитектурного решения `SEC-002` и проектирования `SEC-003`, не смешивая их с текущим UAT-002.2.
5. Отдельная задача: при создании Employee в `/hr/employees` предоставить `admin` и `hr` безопасное действие выпуска цифрового пропуска; в `/identity/digital-passes` добавить тип владельца «Сотрудник». Проверить RBAC, существующий `DigitalIdentity` owner model, отсутствие ПДн в QR, audit, API, frontend и тесты. Не выдавать пропуск автоматически без явного действия пользователя и не изменять существующие student/teacher passes.
6. В штатном окружении выполнить `php artisan test --filter=DigitalIdentityApiTest` и `php artisan test --filter=HrFoundationApiTest`, затем `npm run build` в `frontend`; при успехе провести ручную проверку admin/hr на `/hr/employees` и admin на `/identity/digital-passes`.
7. В штатном backend-окружении выполнить `php artisan test --filter=AuthApiTest`, `php artisan test --filter=UniversalImportApiTest`, `php artisan test --filter=AdminUserApiTest` и `php artisan migrate --pretend`; проверить import student, teacher и employee с `auto_account=да`, вход по email, username и телефону, а также `POST /api/admin/users/provision` под ролью с `users.manage`. Не выводить сгенерированный пароль в результат импорта, audit или документацию; credential card показать только один раз в защищенном admin flow.
8. В штатном backend-окружении выполнить `php artisan test --filter=HrFoundationApiTest`, `php artisan test --filter=PersonFoundationTest` и `php artisan migrate --pretend`; затем импортировать `Сотрудники_Администрация_для_импорта_2026-08-03.xlsx` как `employees` в режиме `skip_duplicates`. Проверить создание отсутствующих должностей, `hired_at = null`, автоназначенные табельные номера, повторный импорт без новых записей и список `/people` без студентов.
9. На DEV вручную создать студента без СНИЛС, проверить русские сообщения валидации, импортировать повторную строку студента без СНИЛС в режиме `skip_duplicates` и сверить карточку с адресом, паспортом, курсом, формой обучения и приказом. После review закоммитить DEV-изменения отдельно, не включая generated `frontend/public/version.json` и `.worktrees/`.
9. Отдельным этапом реализовать self-scoped кабинет роли `employee`: профиль с изменением только ФИО/телефона/email и личную статистику проходов относительно `work_schedule_code`; не выдавать `people.update`, `gate.reports` или общие отчеты.

## Блокеры

- Автоматизированные проверки завершены. Браузерный UAT под teacher требует интерактивной сессии с учётной записью и не заменяется API-тестами.
- Legacy `ScheduleLesson` без `schedule_entry_id` не может автоматически открыть или создать журнал; текущий fallback открывает отфильтрованный журнал по дате и преподавателю.
- Блокеров доступа к DEV нет: passwordless SSH настроен. PROD не изменялся.
- Локально отсутствуют `php` и Docker. Повторный `npm run build` в `frontend` остановился на `vite: command not found`: frontend-зависимости в root worktree неполны. Перед запуском локальной сборки требуется штатная установка зависимостей; она не выполнялась, чтобы не создавать неотслеживаемые изменения.
- PROD не изменялся.

## Чек-лист передачи

Before ending a session or opening a new chat:

1. Run `git status --short`, `git diff --check`, and `git log --oneline -10`.
2. Update this file with branch, local/DEV HEAD, uncommitted work, verified checks, blockers, and exact next actions.
3. Commit completed logical work; do not commit incomplete or unreviewed changes unless explicitly requested.
4. State whether DEV and PROD were changed.
5. Offer a new chat when the task is complete, when the context is near its practical limit, or when the next task is independent.

## Запрос для нового чата

```text
Read AGENTS.md, docs/ACTIVE_WORK.md, TASKS.md, and docs/UAT_002_REPORT.md.
Run git status --short, git diff --check, and git log --oneline -10.
Compare the local branch and DEV HEAD stated in ACTIVE_WORK.md.
Continue only the listed Next Actions. Do not discard uncommitted work.
```
