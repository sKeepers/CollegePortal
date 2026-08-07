# Roadmap разработки CollegePortal

## Project Documentation Map

- [Documentation Index](docs/README.md)
- [Project Status](docs/PROJECT_STATUS.md)
- [Background Agents](docs/AGENTS.md)
- [Roadmap](ROADMAP.md)
- [Tasks](TASKS.md)
- [Changelog](CHANGELOG.md)
- [Project Context](PROJECT_CONTEXT.md)
- [Documentation Report](REPORT.md)

Roadmap фиксирует порядок развития проекта после переноса приемной комиссии в новый Quasar GUI. Backend, БД и API меняются только в задачах, где это отдельно указано.

## Выполнено

### Базовая платформа

- Docker-инфраструктура DEV/PROD;
- Laravel 12 backend;
- Vue 3 + Quasar frontend;
- PostgreSQL;
- авторизация и роли;
- базовая модульная структура проекта;
- UI Foundation, Design System и Layout Guidelines;
- Git workflow и безопасный процесс деплоя DEV -> PROD.

### Основные модули MVP

- студенты;
- группы;
- преподаватели;
- дисциплины;
- аудитории;
- расписание;
- электронный журнал;
- отчеты;
- глобальный поиск;
- Dashboard;
- публичный раздел «Абитуриенту».

### Архитектура

- [x] QR-001: Digital Identity и QR-пропуска Phase 1 — выполнено;
- создан MVP цифровых пропусков для студентов и преподавателей;
- QR содержит только token, без персональных данных.

- [x] ARCH-001: Identity Domain Architecture — выполнено;
- создан `docs/IDENTITY_DOMAIN.md`;
- обновлена доменная модель `docs/DOMAIN_MODEL.md`;
- зафиксирована основа для Person, Digital Identity, QR/Mobile Pass, Access Control, Authentication и Authorization.

### ADM-001: архитектура подсистемы «Приемная комиссия»

- [x] Подготовить архитектурный foundation без реализации бизнес-логики.
- Документы: `docs/ПРИЕМНАЯ_КОМИССИЯ.md`, `docs/adr/ADR-002_ПРИЕМНАЯ_КОМИССИЯ.md`.
- Backlog реализации:
  1. CRUD абитуриентов;
  2. Документы;
  3. Конкурс;
  4. Приказы;
  5. Экспорт в ФИС;
  6. Личный кабинет абитуриента.

### ADM-002: модель данных подсистемы «Приемная комиссия»

- [x] Спроектировать модель данных без миграций, backend, frontend, API и бизнес-логики.
- Документы: `docs/МОДЕЛЬ_ДАННЫХ_ПРИЕМНОЙ_КОМИССИИ.md`, `docs/adr/ADR-003_МОДЕЛЬ_ДАННЫХ_ПРИЕМНОЙ_КОМИССИИ.md`.
- Зафиксированы сущности: Person, Applicant, Application, Choices, Documents, Achievements, Exams, Competitions, Orders, Enrollments, Statuses и Reference Data.
- Следующий рекомендуемый этап: ADM-003 — миграционная стратегия и первый implementation slice с dry-run/backfill.

### ADM-003: API и бизнес-процессы подсистемы «Приемная комиссия»

- [x] Спроектировать REST API, пользовательские сценарии, бизнес-процессы и RBAC без реализации backend/frontend/API/БД.
- Документы: `docs/API_ПРИЕМНОЙ_КОМИССИИ.md`, `docs/RBAC_ПРИЕМНОЙ_КОМИССИИ.md`, `docs/adr/ADR-004_API_ПРИЕМНОЙ_КОМИССИИ.md`.
- Зафиксированы endpoints для абитуриентов, заявлений, выбранных программ, документов, комплектности, достижений, экзаменов, конкурсов, приказов, зачисления, ФИС, истории и справочников.
- Следующий рекомендуемый этап: ADM-004 — миграционная стратегия и первый backend implementation slice.

### ADM-004: техническая стратегия реализации подсистемы «Приемная комиссия»

- [x] Подготовить техническую стратегию реализации backend/frontend без написания кода.
- Документы: `docs/СТРАТЕГИЯ_РЕАЛИЗАЦИИ_ПРИЕМНОЙ_КОМИССИИ.md`, `docs/adr/ADR-005_СТРАТЕГИЯ_РЕАЛИЗАЦИИ.md`.
- Зафиксированы слои Domain, Application, Infrastructure, Repository, Services, Audit, RBAC, private files, migration/testing strategy.
- Подготовлен план первых задач BACK/FRONT/TEST.
- [x] BACK-001: Reference Data, статусы, permissions и read-only API справочников приемной комиссии.
- [x] BACK-002: foundation Person/Applicant, безопасная связь с существующей Person и read-only API абитуриентов.
- [x] BACK-003: foundation `AdmissionApplication`, черновик, редактирование черновика, регистрация, read/write API без DELETE.
- [x] BACK-003.1: изоляция legacy `/admissions` и нового Admissions Foundation через `record_type`, scopes и API guards.
- [x] BACK-004: foundation выбранных образовательных программ заявления с приоритетами и validation rules.
- [x] FRONT-001: read-only workspace нового Admissions Foundation на `/admissions/foundation`.
- [x] BACK-005: foundation документов заявления, private files, СНИЛС и структурированная комплектность.
- [x] BACK-005.1: hardening Documents Foundation — связь заявления с версиями документов, version chain, XSD-поля образования и FIS dictionary mapping.
- [x] FRONT-002: Admissions Foundation editor workspace — мастер создания заявления, документы, файлы, выбранные программы, readiness, FIS blockers и история на `/admissions/foundation`.
- [x] BACK-006: Person & Applicant Management API — `POST/PATCH /api/people`, `POST/PATCH /api/admissions/applicants`, archive Applicant, duplicate check, explicit `merge_not_supported`.
- [x] FRONT-003: Person & Applicant Management UI — создание/редактирование Person, создание/редактирование/архивирование Applicant, duplicate-check и интеграция с мастером заявления на `/admissions/foundation`.
- [x] RC1: Admissions Foundation release candidate — аудит backend/frontend/API/RBAC/docs, release report `RC1_READY.md`, фиксация очевидных замечаний мастера и duplicate-check.
- [x] GUI-009: Dashboard & Navigation Layout Hardening — сворачиваемые группы sidebar, устранение blank `/schedule`, splitter People и compact Dashboard widgets без новых бизнес-функций.
- [x] UAT-002: Portal UX, RBAC and Admissions Stabilization — единый пункт меню приемной комиссии, русские validation/forbidden сообщения, Dashboard без RBAC-noise, существующий QR-пропуск в role-based AppLayout.
- [ ] UAT-002.1: Final portal stabilization — мастер Admissions Foundation с понятной inline-валидацией, ролевые Dashboard без лишних demo-блоков, reusable splitter, единые version/build metadata, HTTPS-only DEV entrypoint, динамический QR 30 секунд, согласованные demo Person/Student/Teacher/Employee и расширенный DEV demo data smoke.
- [x] SEC-001: API token hardening — indexed SHA-256 token lookup, token TTL and login/authenticated API rate limiting после `PROJECT_ANALYSIS.md`.
- [ ] SEC-002: frontend-auth hardening — убрать bearer token из `localStorage`, перейти на HttpOnly Secure cookie/Sanctum или другой backend-controlled session flow.
- [ ] SEC-003: production security hardening — encryption-at-rest для ПДн/backups/private storage, TLS/security headers, CSP/HSTS и retention policies.
- Следующий рекомендуемый этап после UAT-002.1: TEST-001 — regression suite полного workspace приемной комиссии, включая registered read-only и duplicate-check сценарии.
### Приемная комиссия

- [x] ADM-001: Приемная комиссия — выполнено;
- [x] ADM-001.1: Полировка приемной комиссии — выполнено.

Реализовано:

- реестр заявлений абитуриентов;
- поиск и фильтры;
- быстрые статусы;
- карточка заявления справа;
- документы заявления;
- история событий;
- импорт и экспорт CSV;
- создание, редактирование и удаление заявления;
- зачисление абитуриента в студенты через существующий API;
- добавление заявлений в глобальный поиск.

## Ближайший порядок развития

### 1. GUI-015: Учебные планы

Цель: добавить основу работы с учебными планами и связать их с программами, группами, дисциплинами, расписанием и журналом.

План:

- список учебных планов;
- карточка учебного плана;
- связь с образовательной программой и специальностью;
- дисциплины плана;
- семестры;
- часы, формы контроля и практики;
- подготовка данных для нагрузки преподавателей и расписания.

### 2. QR / Проходная

QR-пропуска и проходная уже реализованы в рамках Digital Identity / Access Gate и проходят UAT как существующий модуль, а не новая реализация.

Текущий план стабилизации:

- не создавать второй QR backend/API;
- переиспользовать `/api/digital-identities`, `/api/digital-identities/{id}/qr`, `/api/access/scan` и существующие страницы проходной;
- вывести `Мой QR-пропуск` для Student/Teacher/HR;
- оставить `Цифровые пропуска`, `Проходная`, `Мобильный сканер`, `Отчеты по проходам` только административным и security-ролям;
- проверить действующий стенд `https://192.168.34.104:5443/` и текущий DEV `http://192.168.34.114:5174/`.
- основной текущий DEV для мобильного UAT: `https://192.168.34.114:5443/`; порт `5174` является HTTP-only.
- мобильный QR студента должен обновляться каждые 30 секунд и не содержать постоянный token пропуска.

### 3. MOB-001: Mobile Student Cabinet Phase 1

Цель: подготовить мобильный кабинет студента на базе существующего frontend-стека и будущей Digital Identity.

План:

- расписание студента;
- оценки;
- посещаемость;
- уведомления;
- мобильный QR-пропуск на базе Digital Identity;
- базовый профиль студента;
- адаптация под мобильные экраны;
- разделение интерфейса сотрудника и студента.

### 4. GRAD-001: Выпускники и дипломы

Цель: подготовить контур выпуска, дипломов и истории выдачи документов.

План:

- реестр выпускников;
- связь выпускника с бывшей карточкой студента;
- шаблоны дипломов;
- печать дипломов;
- приложения к диплому;
- история выдачи;
- статусы документов: подготовлен, напечатан, выдан, аннулирован;
- подготовка данных для ФРДО.

### 5. FRDO-001: Подготовка данных ФРДО

Цель: собрать и проверить данные, необходимые для будущей выгрузки во ФРДО.

План:

- подготовка данных студентов и выпускников;
- персональные данные: СНИЛС, пол, гражданство, документ об образовании;
- данные диплома и приложения;
- проверка полноты;
- выгрузка;
- журнал отправки;
- ошибки и статусы;
- повторная подготовка после исправления ошибок.

### 6. FIS-001: ФИС ГИА / ФИС Приема

Цель: подготовить интеграционный контур для приемной кампании и государственных систем приема.

План:

- приемные кампании;
- конкурсные группы;
- данные абитуриентов;
- документы абитуриентов;
- приказы о зачислении;
- экспорт/импорт;
- журнал обмена;
- ошибки и статусы;
- подготовка к XML/XSD-форматам ФИС ГИА/Приема.

### 7. GUI-016: Нагрузка преподавателей

Цель: связать учебные планы, дисциплины, группы и преподавателей в модуль планирования нагрузки.

План:

- расчет часов по дисциплинам;
- распределение нагрузки между преподавателями;
- контроль перегрузки;
- связь с расписанием;
- отчеты по нагрузке.

### 8. GUI-017: Экзамены / ГИА

Цель: создать контур экзаменов, государственной итоговой аттестации и связанных документов.

План:

- расписание экзаменов;
- экзаменационные ведомости;
- комиссии;
- результаты;
- связь с журналом;
- подготовка данных для ФИС ГИА.

## Дальнейшие направления

### Moodle

- синхронизация пользователей;
- синхронизация групп;
- связь дисциплин с курсами Moodle;
- журнал обмена;
- статусы синхронизации.

### Analytics

- Dashboard по ролям;
- аналитика посещаемости;
- аналитика успеваемости;
- аналитика приемной комиссии;
- аналитика опозданий и отсутствий.

### Person и Digital Identity

- центральная сущность Person;
- роли Student, Teacher, Employee, Applicant, Guest, Alumni;
- QR, мобильный QR, печатный QR;
- срок действия цифровой идентичности;
- будущие этапы: NFC, распознавание лиц, IP-камеры, AI-анализ опозданий и отсутствий.

## MILESTONE-002: ревизия после QR, Mobile, Graduation, FRDO и FIS

- [x] MILESTONE-002: подготовлена ревизия текущего состояния проекта;
- зафиксирован список реализованных модулей, маршрутов, backend-сущностей и тестов;
- подтверждены проверки DEV: `php artisan test` — 130 passed, `npm run build` — успешно;
- отдельный отчет: `docs/MILESTONE_002_REVIEW.md`.

### Ближайший фокус после MILESTONE-002

- пользовательская проверка Admissions, QR/Access, Mobile Student, Graduation, FRDO и FIS;
- полировка UX новых MVP-модулей;
- наполнение реальными справочниками и тестовыми учебными данными колледжа;
- матрица ролей и прав доступа;
- оптимизация frontend-сборки через code splitting;
- сверка FRDO/FIS с актуальными официальными форматами.

## UAT-001: подготовка к пользовательскому тестированию

- [x] UAT-001: подготовлены документы для первого цикла User Acceptance Testing;
- создан план UAT, чек-листы по ролям, список ограничений MVP, release notes и сквозные сценарии;
- следующий шаг: провести UAT на тестовых данных колледжа и сформировать список замечаний по приоритетам Critical/High/Medium/Low/UX.

## UAT-002: первый пакет улучшений по результатам анализа MVP

- [x] UAT-002: реализовано управление демо-данными, автокоды и фото Person для текущих MVP-сущностей;
- [x] добавлены проверки автокодов, фото и запрета очистки демо-данных в production;
- следующий шаг: провести UAT на тестовых данных и сформировать пакет замечаний UAT-003.

## IMPORT-001: универсальный импорт реальных данных

- [x] IMPORT-001: создан MVP универсального импорта CSV/XLSX;
- [x] поддержаны студенты, группы, преподаватели, дисциплины, аудитории и абитуриенты;
- [x] добавлены preview, mapping, validation, confirm и история импортов;
- следующий шаг: подготовить официальные шаблоны Excel для колледжа и провести пробный импорт реальных тестовых выгрузок.

## RELEASE-007: Release 0.7 Freeze Review

- [x] Зафиксировано состояние CollegePortal Release 0.7 перед пилотной загрузкой данных.
- [x] Проверены backend-тесты: `161 passed (742 assertions)`.
- [x] Проверена frontend-сборка: `npm run build` успешно.
- [x] Проверены ключевые маршруты DEV, включая учебные, административные, Identity/Access, Mobile, FRDO/FIS и legacy-разделы.
- [x] Создан документ `docs/RELEASE_0_7_FREEZE_REVIEW.md`.

### Ближайший фокус после Release 0.7

- согласовать реальные справочники колледжа;
- подготовить контрольные CSV/XLSX для пилотного импорта;
- выполнить пилотную загрузку сначала в DEV;
- провести выборочную сверку студентов, групп, преподавателей, дисциплин, аудиторий и абитуриентов;
- сформировать список замечаний UAT/IMPORT перед любым PROD-deploy.

## DATA-001: подготовка пилотной загрузки данных

- [x] Подготовлен план пилотной загрузки реальных данных в DEV.
- [x] Зафиксирован порядок загрузки: справочники, специальности, образовательные программы, группы, преподаватели, дисциплины, аудитории, студенты, учебные планы, нагрузка, расписание, абитуриенты.
- [x] Созданы описания шаблонов в `docs/import-templates/`.
- [x] Создан чек-лист качества реальных Excel/CSV данных.

### Следующий фокус

- собрать реальные файлы от ответственных подразделений;
- привести справочники к значениям колледжа;
- выполнить малую пилотную загрузку в DEV;
- зафиксировать ошибки импорта и требования к IMPORT-002.

## DATA-002: расширение универсального импорта

- [x] Добавлен импорт учебных планов через `/admin/import`.
- [x] Добавлен импорт нагрузки преподавателей через `/admin/import`.
- [x] Добавлен импорт расписания через `/admin/import`.
- [x] CSV-шаблоны и описания `docs/import-templates/` обновлены до статуса поддерживаемых.

### Следующий фокус

- провести малую пилотную загрузку учебных планов, нагрузки и расписания;
- проверить реальные конфликты расписания на данных колледжа;
- по результатам пилота сформировать DATA-003/IMPORT-002 с улучшениями mapping и ошибок.

## EPIC-001: Architecture Review & Technical Debt

- [x] Проведена ревизия backend/frontend архитектуры после Release 0.7.
- [x] Зафиксирован технический долг: крупный `UniversalImportService`, legacy `App.vue`, большие CRUD-страницы, широкие permissions.
- [x] Подготовлены performance и security reviews.
- [x] Подготовлен refactor plan без изменения функциональности.

### Ближайший технический фокус

- REF-001: characterization tests для универсального импорта;
- REF-002: разделить `UniversalImportService` на parser, registry и target handlers;
- REF-010: lazy loading frontend routes;
- REF-020: детализировать permission matrix;
- REF-030: подготовить owner/person resolver foundation.

## RBAC-001: Permission Matrix

- [x] Реализовать permission platform и матрицу ролей.
- [x] Добавить `/admin/permissions`.
- [x] Защитить API точечными permissions.
- [ ] RBAC-002: ownership-политики для преподавателей и студентов.
- [ ] RBAC-003: скрытие action-level кнопок во всех CRUD-разделах.


## PERSON-001 завершено

- Создана базовая сущность Person.
- Добавлены связи с Student, Teacher, Applicant, Graduate, User и DigitalIdentity.
- Подготовлена безопасная команда связывания существующих профилей.
- Следующие этапы: ручная проверка дублей, UI для linking, осторожный merge Person, перенос фото на Person как основной источник.

## ADM-DOCS-001 завершено

- Добавлен registry документов абитуриента и приватное хранение файлов.
- Комплектность Admissions переведена на реальные типы документов из Reference Data.
- Добавлены permissions, audit и sync-команда для legacy-заявлений.
- Следующий этап: расширить UX bulk-панели документов и добавить пользовательскую проверку реальных файлов приемной комиссии.

## ST-001A завершено

- Добавлен Curriculum Engine foundation.
- Учебный план получил нормализованные `curriculum_subjects`.
- Добавлены API subjects/semesters/summary.
- Группа получила ссылку на действующий учебный план.
- Следующие этапы: связать Teaching Load и Schedule с `curriculum_subjects`, добавить преподавателей и типы занятий по строкам плана.

## ST-001B завершено

- Добавлена генерация Teaching Load из Curriculum Engine.
- Добавлены preview/apply, coverage и назначение преподавателей строкам нагрузки.
- Следующие этапы: связать Schedule с generated load, добавить распределение строк между несколькими преподавателями и проверку свободных часов.

## ST-002A завершено

Schedule Engine foundation реализован. Следующие этапы: полноценный редактор недельных шаблонов, расширенная работа с заменами и будущий автогенератор расписания на основе нагрузки.

## ST-002B завершено

Visual Schedule Editor добавлен как MVP. Следующие этапы: полноценное редактирование многострочных шаблонов, resize занятий, массовое применение на диапазон и безопасная очистка шаблонных записей.

## Release Packaging

- [x] INFRA-007: installer distribution and lifecycle tools for clean Ubuntu Server deployments.
- [ ] Future: validate installer on a separate VM with real TLS modes and backup/restore round-trip.

## Study Process

- [x] ST-003A: Electronic Journal Foundation linked to Schedule Engine.
- [ ] ST-003B: printable journal forms and period exports.

## ST-003B завершено

Teacher Journal Workspace добавлен поверх Journal Engine. Следующие этапы: печатные формы журнала, расширенная клавиатурная навигация, групповые отчеты по периоду и UX-проверка преподавателями на реальном расписании.

## UAT-003 завершено

Role-based UAT center добавлен. Следующие этапы: провести первый закрытый цикл с реальными сотрудниками, подтвердить критичные замечания и сформировать пакет исправлений UAT-004.

## HR-001A выполнено

Кадровый контур сотрудников добавлен как foundation для будущих HR-документов, учета рабочего времени, интеграции с расписанием и отчетов. Следующие этапы: HR-документы, кадровые приказы, отчеты по штатному расписанию и расширение Dashboard KPI.

## HR-001B выполнено

Кадровый календарь и замены преподавателей реализованы как MVP. Следующие HR-этапы: кадровые документы, приказы, печатные формы, согласование замен и Notification Center.

## INFRA-008 completed

Installer acceptance on a clean UAT server is complete for 0.8.0-rc2. Trusted TLS is done since `SEC-004`: the certificate for `portal.skki.ru` is issued and renews itself, and the release proxy forces HTTPS and sends security headers. Remaining pre-PROD hardening: SSH keys and password rotation, and a clearer repeated-install message.


## FIS-API-001 / 0.9

Official outbound connector foundation is started for Release 0.9: official sources manifest, private XSD validation, outbound package lifecycle, mock transport, connection diagnostics and production lock. Next blocker: official specification/WSDL/XSD and TEST credentials/access through ZKSPD.


## FIS-API-001.1

Official FIS outbound connector remains in 0.9 track. Next required step: obtain FCT 4.9 specification/XSD/test client and run Gateway Agent on a ZKSPD-connected node for TEST endpoint verification.

## FIS-GATEWAY-001

FIS-GATEWAY-001: ViPNet Gateway Agent for FIS TEST diagnostics and future official outbound connector.

## REPO-SYNC-001

REPO-SYNC-001 completed: repository sync documentation and safe Linux/Windows helpers.

## INTEGRATION-HUB-001

CollegePortal Gateway foundation added: FIS Gateway Agent is generalized into a modular Windows service architecture for protected integrations. FIS remains the only implemented adapter; future FRDO/Moodle/LDAP/MAX/Telegram/Email adapters are planned. Windows repo path is `C:\!Projects\CollegePortal`; ViPNet installation remains a separate task.
