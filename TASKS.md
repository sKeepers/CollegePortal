# Задачи для Codex

## Project Documentation Map

- [Documentation Index](docs/README.md)
- [Project Status](docs/PROJECT_STATUS.md)
- [Background Agents](docs/AGENTS.md)
- [Roadmap](ROADMAP.md)
- [Tasks](TASKS.md)
- [Changelog](CHANGELOG.md)
- [Project Context](PROJECT_CONTEXT.md)
- [Documentation Report](REPORT.md)

Документы проекта нужно обновлять периодически: после добавления новых модулей, изменения схемы БД, заметного изменения интерфейса или изменения плана разработки.

## Правила работы

- [x] GITHUB-002: добавить полноценное русскоязычное представление CollegePortal на GitHub

- После каждой выполненной задачи делать Git checkpoint в `/srv/college-dev`: `git status`, проверка файлов, commit с номером задачи, без `.env`, `vendor`, `node_modules`, `tmp` и logs.

## Сначала

- [x] Создать docker-compose.yml
- [x] Создать структуру backend/frontend
- [x] Подготовить .env.example
- [x] Подготовить миграции
- [x] Создать модели
- [x] Создать seeders ролей
- [x] Создать тестовые данные

## Затем

- [x] API для групп
- [x] API для студентов
- [x] API для преподавателей
- [x] API для дисциплин
- [x] API для аудиторий
- [x] API для расписания
- [x] API для посещаемости
- [x] API для оценок

## Frontend

- [x] Layout
- [x] Login page
- [x] ADR-001: выбрать UI-платформу Quasar + Tailwind CSS + Lucide Icons
- [x] Создать Quasar-каркас нового GUI без удаления старого App.vue
- [x] Настроить Vue Router для нового GUI
- [x] Настроить Pinia auth-store для нового GUI
- [x] Создать layout-компоненты AppLayout, AuthLayout, PublicLayout
- [x] Создать страницы-заглушки нового GUI
- [x] Перенести авторизацию в новый GUI
- [x] GUI-002: создать UI Foundation поверх Quasar, Tailwind CSS и Lucide Icons
- [x] Создать базовые UI-компоненты нового GUI
- [x] Создать страницу `/system/ui-foundation` для администратора
- [x] GUI-003: перенести раздел «Студенты» в новый Quasar GUI
- [x] Использовать UI Foundation-компоненты в новом разделе «Студенты»
- [x] GUI-003.1: отполировать раздел «Студенты» как эталонный CRUD-модуль
- [x] Добавить активные фильтры-чипы, уведомления, вкладки карточки студента и плотную рабочую компоновку
- [x] GUI-003.2: настроить русскую локализацию таблиц и сохранение размера страницы студентов
- [x] GUI-004: перенести раздел «Группы» в новый Quasar GUI по образцу раздела «Студенты»
- [x] GUI-005: связать разделы «Студенты» и «Группы» через карточки и query-параметры
- [x] GUI-005.1: отполировать карточку группы, упростить визуальную структуру и перенос длинных значений
- [x] GUI-005.2: исправить layout страниц «Группы» и «Студенты», исключить наложение карточки на таблицу
- [x] GUI-006: создать фундамент глобального поиска по студентам и группам с расширяемой архитектурой
- [x] GUI-006.1: исправить горячие клавиши глобального поиска, фокус по Ctrl+K/Cmd+K и закрытие по ESC
- [x] GUI-006.2: заменить верхний поиск на псевдо-input и перенести ввод в реальный input внутри GlobalSearch
- [x] GUI-007: создать виджетный Dashboard Foundation
- [x] GUI-008: проверить и исправить layout рабочих страниц после Dashboard, исключить перекрытие карточкой таблицы
- [x] GUI-009: перенести раздел «Расписание» в новый Quasar GUI с представлениями день/неделя/преподаватель/группа/аудитория
- [x] GUI-010: создать новый read-only электронный журнал в Quasar GUI на основе существующих API
- [x] GUI-011: создать Responsive Workspace Foundation, layout service и workspace store
- [x] GUI-011A: заменить Quasar favicon и подготовить брендовые иконки CollegePortal
- [x] GUI-011A.1: заменить сгенерированный знак на официальный черно-белый логотип СККИ
- [x] GUI-011B: добавить визуальную индикацию DEV/TEST/PROD и title браузера по окружению
- [x] VER-001: добавить отображение версии и build-информации в sidebar и окно `О системе`
- [x] VER-001.1: исправить layout sidebar после добавления version footer
- [x] UX-001: создать единый WorkspacePanel для правых карточек и подключить к ключевым сущностям
- [x] UX-001.1: перевести учебные планы, нагрузку, выпуск, приемную комиссию и цифровые пропуска на WorkspacePanel
- [x] UX-001.2: завершить перевод экзаменов, расписания, журнала, ФРДО, ФИС и отчетов по проходам на WorkspacePanel
- [x] UX-002: создать ролевые Dashboard Phase 1 для admin/director и teacher
- [x] INFRA-001: создать отдельное DEV-окружение `/srv/college-dev` рядом с текущим PROD
- [x] INFRA-002: подготовить безопасный документированный процесс деплоя DEV → PROD без изменения PROD
- [x] INFRA-003: подготовить Git workflow для DEV/PROD без изменения PROD
- [x] INFRA-004: безопасно инициализировать Git в DEV на ветке `develop` без remote
- [x] INFRA-005: документировать обязательный Git checkpoint после каждой задачи
- [x] DESIGN-001: создать единую дизайн-систему CollegePortal
- [x] ARCH-001: спроектировать Identity Domain Architecture
- [x] QR-001: Digital Identity и QR-пропуска Phase 1
- [x] GUI-015: создать модуль учебных планов `/curricula`
- [x] GUI-016: создать модуль нагрузки преподавателей `/teaching-load`
- [x] GUI-017: создать модуль экзаменов и ГИА `/exams`
- [x] GRAD-001: создать модуль выпускников и дипломов `/graduation`
- [x] FRDO-001: создать модуль подготовки данных ФРДО `/frdo`
- [x] FIS-001: создать модуль подготовки данных ФИС ГИА / ФИС Приема `/fis`
- [x] GUI-012: перенести раздел «Преподаватели» в новый Quasar GUI
- [x] GUI-013: перенести раздел «Дисциплины» в новый Quasar GUI
- [x] GUI-014: перенести раздел «Аудитории» в новый Quasar GUI
- [x] ADM-001: перенести раздел «Приемная комиссия» в новый Quasar GUI
- [x] ADM-001.1: проверить и отполировать раздел «Приемная комиссия»
- [x] Dashboard
- [x] Students page
- [x] Groups page
- [x] Teachers page
- [x] Subjects page
- [x] Classrooms page
- [x] Schedule page
- [x] Journal page
- [x] Journal workflow: выбор занятия, отметка посещаемости и выставление оценок
- [x] Редактирование и удаление записей в основных разделах интерфейса
- [x] Импорт и экспорт студентов через CSV
- [x] Импорт и экспорт групп через CSV
- [x] Импорт и экспорт преподавателей через CSV
- [x] Импорт и экспорт дисциплин через CSV
- [x] Импорт и экспорт аудиторий через CSV
- [x] Справочник специальностей
- [x] Справочник образовательных программ
- [x] Связь групп с образовательными программами
- [x] Публичный раздел «Абитуриенту» со специальностями и программами
- [x] Фильтры студентов и расписания
- [x] Карточка студента с основными данными, контактами, посещаемостью и оценками
- [x] Отчет по посещаемости группы с экспортом CSV
- [x] Отчет по оценкам группы с экспортом CSV

## ADM-001: архитектура подсистемы «Приемная комиссия»

- [x] Создать архитектурный документ `docs/ПРИЕМНАЯ_КОМИССИЯ.md`.
- [x] Создать ADR `docs/adr/ADR-002_ПРИЕМНАЯ_КОМИССИЯ.md`.
- [x] Зафиксировать структуру backend/frontend без реализации кода.
- [x] Зафиксировать модель данных, роли, API, интеграции и будущий ФИС-контур.
- [x] Подготовить backlog ADM-001:
  - Этап 1: CRUD абитуриентов;
  - Этап 2: Документы;
  - Этап 3: Конкурс;
  - Этап 4: Приказы;
  - Этап 5: Экспорт в ФИС;
  - Этап 6: Личный кабинет абитуриента.

## ADM-002: модель данных подсистемы «Приемная комиссия»

- [x] Создать документ `docs/МОДЕЛЬ_ДАННЫХ_ПРИЕМНОЙ_КОМИССИИ.md`.
- [x] Создать ADR `docs/adr/ADR-003_МОДЕЛЬ_ДАННЫХ_ПРИЕМНОЙ_КОМИССИИ.md`.
- [x] Описать сущности: Персона, Абитуриент, Заявление, Выбранная специальность, Документ, Индивидуальные достижения, Экзамен, Конкурс, Приказ, Зачисление, Статусы и Справочники.
- [x] Для каждой сущности зафиксировать назначение, поля, обязательность, связи, ограничения, индексы, архивирование и аудит.
- [x] Добавить Mermaid ER-диаграмму.
- [x] Зафиксировать подготовку к миграциям без создания миграций.
- [ ] ADM-003: подготовить миграционную стратегию и первый implementation slice.

## ADM-003: API и бизнес-процессы подсистемы «Приемная комиссия»

- [x] Создать документ `docs/API_ПРИЕМНОЙ_КОМИССИИ.md`.
- [x] Создать документ `docs/RBAC_ПРИЕМНОЙ_КОМИССИИ.md`.
- [x] Создать ADR `docs/adr/ADR-004_API_ПРИЕМНОЙ_КОМИССИИ.md`.
- [x] Описать сценарии: создание абитуриента, создание и редактирование заявления, документы, комплектность, регистрация, конкурс, приказ, зачисление, ФИС.
- [x] Описать REST endpoints с URL, методом, назначением, параметрами, телом, ответом, ошибками и permission.
- [x] Добавить Mermaid-диаграммы жизненного цикла, статусов и последовательности обработки.
- [x] Зафиксировать RBAC, audit и действия с подтверждением.
- [ ] ADM-004: подготовить миграционную стратегию и первый backend implementation slice.

## ADM-004: техническая стратегия реализации подсистемы «Приемная комиссия»

- [x] Создать документ `docs/СТРАТЕГИЯ_РЕАЛИЗАЦИИ_ПРИЕМНОЙ_КОМИССИИ.md`.
- [x] Создать ADR `docs/adr/ADR-005_СТРАТЕГИЯ_РЕАЛИЗАЦИИ.md`.
- [x] Зафиксировать структуру backend/frontend каталогов.
- [x] Описать Domain, Application, Infrastructure, Repository и Services слои.
- [x] Описать Audit, RBAC, private files, миграции и тестирование.
- [x] Подготовить план BACK-001..BACK-010, FRONT-001..FRONT-006, TEST-001..TEST-002.
- [x] BACK-001: Reference Data, статусы, permissions и read-only API справочников приемной комиссии.
- [x] BACK-002: Applicant foundation и безопасная связь с Person.
- [x] BACK-003: Application foundation.
- [x] BACK-003.1: изоляция legacy `/admissions` и нового Admissions Foundation через явный `record_type`.
- [x] BACK-004: Program Choices foundation для выбранных образовательных программ заявления с приоритетами.
- [ ] BACK-005: следующий backend slice после review choices: документы заявления или frontend workspace.
- [ ] FRONT-001: Read-only Admissions workspace.
- [ ] TEST-001: Backend regression suite.
## Приемная комиссия

- [x] Справочник специальностей СПО
- [x] Справочник образовательных программ
- [x] Публичный раздел «Абитуриенту»
- [x] Фильтр публичных программ по форме обучения
- [x] Реестр заявлений абитуриентов
- [x] Фильтры заявлений по поиску, программе, статусу и базе поступления
- [x] Импорт заявлений через CSV
- [x] Экспорт заявлений через CSV
- [x] Подробные ошибки импорта по строкам CSV
- [x] История событий заявления
- [x] Обязательные документы абитуриента
- [x] Отметка получения документов
- [x] Дата, номер и комментарий по документу
- [x] Сводка комплектности документов
- [x] Быстрые очереди: готовы к зачислению, неполный комплект, зачислены
- [x] Активные фильтры заявлений с быстрым сбросом
- [x] Зачисление абитуриента в студенты
- [x] Запрет зачисления без полного комплекта документов

## Архитектура

- [x] Создать docs/IDENTITY_DOMAIN.md
- [x] Обновить docs/DOMAIN_MODEL.md
- [x] Обновить docs/ARCHITECTURE_DOCUMENTATION.md
- [x] Зафиксировать Person, Digital Identity, QR/Mobile Pass и Access Control

## Цифровые пропуска

- [x] Создать таблицу `digital_identities`
- [x] Реализовать API выпуска, списка, отзыва и QR
- [x] Создать раздел `/identity/digital-passes`
- [x] Добавить пункт меню `Identity / Цифровые пропуска`
- [x] Проверить, что QR не содержит персональные данные

## Следующие задачи

- [ ] QR-002: Проходная / проверка QR-token
- [ ] MOB-001: Mobile Student Cabinet Phase 1
- [ ] GRAD-001: Выпускники и дипломы
- [ ] FRDO-001: Подготовка данных ФРДО
- [ ] FIS-001: ФИС ГИА / ФИС Приема


## Экзамены и ГИА

- [x] Создать таблицы `exams` и `exam_results`
- [x] Реализовать API экзаменов, результатов, импорта и экспорта CSV
- [x] Создать раздел `/exams` в новом Quasar GUI
- [x] Добавить фильтры по учебному году, группе, дисциплине, преподавателю и типу
- [x] Добавить карточку экзамена справа и таблицу результатов студентов
- [x] Добавить быстрые переходы к группе, дисциплине, преподавателю, аудитории и журналу


## Выпускники и дипломы

- [x] Создать таблицы `graduates`, `diplomas`, `diploma_supplements`
- [x] Реализовать API выпускников, дипломов, приложений, импорта и экспорта CSV
- [x] Создать раздел `/graduation` в новом Quasar GUI
- [x] Добавить фильтры по году выпуска, группе, программе и статусу диплома
- [x] Добавить карточку выпускника с вкладками `Общие сведения`, `Диплом`, `Приложение`, `История`
- [x] Добавить быстрые переходы к студенту, группе, экзаменам/ГИА и подготовке к ФРДО


## ФРДО

- [x] Создать таблицы `frdo_packages`, `frdo_records`, `frdo_validation_errors`
- [x] Реализовать API подготовки пакетов, проверки полноты, CSV/JSON выгрузки и статусов
- [x] Создать раздел `/frdo` в новом Quasar GUI
- [x] Добавить фильтры по году выпуска, статусу и образовательной программе
- [x] Добавить карточку пакета справа и таблицу записей внутри пакета
- [x] Добавить отображение ошибок валидации
- [x] Создать документацию `docs/FRDO.md`


## ФИС ГИА / ФИС Приема

- [x] Создать таблицы `fis_packages`, `fis_records`, `fis_validation_errors`
- [x] Реализовать API подготовки пакетов приема и ГИА, проверки полноты, CSV/JSON выгрузки и статусов
- [x] Создать раздел `/fis` в новом Quasar GUI
- [x] Добавить фильтры по типу, году, статусу и образовательной программе
- [x] Добавить карточку пакета справа и список записей внутри пакета
- [x] Добавить отображение ошибок валидации
- [x] Создать документацию `docs/FIS.md`

## Отложенные направления

- [ ] Интеграция с Moodle
- [ ] Экспорт Excel/PDF для отчетов
- [ ] Расширенная аналитика Dashboard

## MILESTONE-002

- [x] MILESTONE-002: подготовить ревизию текущего состояния CollegePortal после QR, Mobile, Graduation, FRDO и FIS
- [x] Создать `docs/MILESTONE_002_REVIEW.md`
- [x] Зафиксировать реализованные модули, маршруты и backend-сущности
- [x] Зафиксировать результаты `php artisan test` и `npm run build`
- [x] Обновить `PROJECT_CONTEXT.md`, `ROADMAP.md` и `TASKS.md`
- [x] Сделать Git checkpoint

## UAT-001

- [x] UAT-001: подготовить CollegePortal к первому циклу пользовательского тестирования
- [x] Создать `docs/UAT_PLAN.md`
- [x] Создать `docs/UAT_CHECKLIST.md`
- [x] Создать `docs/KNOWN_LIMITATIONS.md`
- [x] Создать `docs/RELEASE_NOTES_MILESTONE_002.md`
- [x] Создать `docs/TEST_SCENARIOS.md`
- [x] Обновить `PROJECT_CONTEXT.md`, `ROADMAP.md`, `TASKS.md`
- [x] Сделать Git checkpoint

## UAT-002

- [x] UAT-002: реализовать первый пакет улучшений после анализа MVP
- [x] Добавить раздел `Администрирование -> Управление данными`
- [x] Добавить создание, очистку, импорт и экспорт демо-данных
- [x] Запретить очистку демо-данных в production
- [x] Добавить автоматический код для специальностей, дисциплин и учебных планов
- [x] Добавить автоматическое название группы при пустом значении
- [x] Добавить фото для студентов, преподавателей и выпускников
- [x] Использовать фото в мобильном кабинете и на проходной
- [x] Создать `docs/UAT_IMPROVEMENTS.md`
- [x] Добавить backend feature-тесты для UAT-002
- [x] Сделать Git checkpoint

## UAT-002.1

- [x] Проверить и отполировать улучшения UAT-002
- [x] Проверить `/admin/data-management`: создание, импорт, экспорт, очистка демо-данных
- [x] Исправить безопасную очистку demo-записей со связанными данными
- [x] Проверить автокоды дисциплины, специальности, учебного плана и группы
- [x] Проверить загрузку, замену, удаление и валидацию фото
- [x] Проверить маршруты `/admin/data-management`, `/students`, `/teachers`, `/graduation`, `/m/student`, `/access/gate`, `/legacy`
- [x] Прогнать `php artisan test` и `npm run build`
- [x] Сделать Git checkpoint

## IMPORT-001

- [x] Создать раздел `/admin/import`
- [x] Поддержать импорт студентов, групп, преподавателей, дисциплин, аудиторий и абитуриентов
- [x] Добавить загрузку CSV/XLSX
- [x] Добавить предварительный просмотр
- [x] Добавить сопоставление колонок
- [x] Добавить проверку ошибок до подтверждения
- [x] Добавить режимы создания, обновления и пропуска дублей
- [x] Добавить отчет импорта
- [x] Сохранять историю импортов в `import_jobs`
- [x] Создать `docs/DATA_IMPORT.md`
- [x] Проверить `php artisan test` и `npm run build`
- [x] Сделать Git checkpoint

## IMPORT-001.1

- [x] Добавить скачивание CSV-шаблонов для студентов, групп, преподавателей, дисциплин, аудиторий и абитуриентов
- [x] Добавить русские названия колонок и примерные строки в шаблонах
- [x] Добавить подсказки по обязательным полям, ключам обновления и примерам значений
- [x] Улучшить ошибки импорта: строка, колонка, причина, исходное значение
- [x] Проверить `php artisan test` и `npm run build`
- [x] Сделать Git checkpoint


## CORE-001A

- [x] Создать раздел `/admin/users`
- [x] Добавить пункт меню `Система -> Пользователи`
- [x] Добавить поля `person_type` и `person_id` в `users`
- [x] Добавить API управления пользователями, блокировки и разблокировки
- [x] Создать frontend page и Pinia store
- [x] Добавить UAT demo-пользователей через seeder с production-защитой
- [x] Создать `docs/USERS_AND_ROLES.md`
- [x] Проверить `php artisan test` и `npm run build`
- [x] Сделать Git checkpoint


## CORE-001B

- [x] Добавить таблицу `role_user`
- [x] Поддержать роли `admin`, `director`, `deputy`, `study`, `admission`, `teacher`, `student`, `security`
- [x] Добавить API `/api/admin/roles`
- [x] Добавить API назначения ролей пользователю
- [x] Создать раздел `/admin/roles`
- [x] Добавить пункт меню `Система -> Роли`
- [x] Показывать реальные роли в карточке пользователя `/admin/users`
- [x] Обновить UAT seeder назначениями ролей
- [x] Обновить `docs/USERS_AND_ROLES.md`
- [x] Проверить `php artisan test` и `npm run build`
- [x] Сделать Git checkpoint


## CORE-002

- [x] Создать таблицу `audit_logs`
- [x] Создать `AuditLog`, `AuditLogService`, `AuditLogResource`, `AuditLogController`
- [x] Добавить единый метод `AuditLogService::log(...)`
- [x] Подключить логирование auth, users, roles, import, QR и demo-data
- [x] Добавить API `/api/admin/audit` и `/api/admin/audit/{id}`
- [x] Создать frontend-раздел `/admin/audit`
- [x] Создать `docs/AUDIT_LOG.md`
- [x] Проверить `php artisan migrate`, `php artisan test`, `npm run build`
- [x] Сделать Git checkpoint


## CORE-003

- [x] Создать таблицу `settings`
- [x] Создать `Setting`, `SettingService`, `AdminSettingController`, `SettingResource`
- [x] Добавить API `/api/admin/settings` и `/api/settings/public`
- [x] Создать frontend-раздел `/admin/settings`
- [x] Добавить пункт меню `Система -> Настройки колледжа`
- [x] Подключить публичные настройки к интерфейсу и проходной
- [x] Создать `docs/SETTINGS.md`
- [x] Проверить `php artisan migrate`, `php artisan test`, `npm run build`
- [x] Сделать Git checkpoint


## CORE-004A

- [x] Создать таблицы `reference_catalogs` и `reference_items`
- [x] Создать модели, ресурсы и API справочников
- [x] Добавить системный `ReferenceDataSeeder`
- [x] Создать frontend-раздел `/admin/reference`
- [x] Добавить пункт меню `Система -> Справочники`
- [x] Создать `docs/REFERENCE_DATA.md`
- [x] Проверить `php artisan migrate`, `php artisan test`, `npm run build`
- [x] Сделать Git checkpoint


## CORE-004B

- [x] Добавить backend `ReferenceService` с кэшированием
- [x] Подключить frontend-store `referenceOptions`
- [x] Перевести Students на `student_statuses`
- [x] Перевести Admissions на `applicant_application_statuses`
- [x] Перевести Exams на `exam_types`
- [x] Перевести Teaching Load на `teaching_load_types`
- [x] Перевести Graduation на `diploma_statuses`
- [x] Обновить `docs/REFERENCE_DATA.md`
- [x] Проверить `php artisan test`, `npm run build`
- [x] Сделать Git checkpoint

## RELEASE-007

- [x] Создать `docs/RELEASE_0_7_FREEZE_REVIEW.md`
- [x] Описать реализованные модули Release 0.7
- [x] Зафиксировать состояние тестов и frontend build
- [x] Зафиксировать состояние DEV/PROD
- [x] Проверить ключевые маршруты DEV
- [x] Описать готовность к пилотной эксплуатации
- [x] Описать известные ограничения и риски перед загрузкой реальных данных
- [x] Обновить `PROJECT_CONTEXT.md`
- [x] Обновить `ROADMAP.md`
- [x] Обновить `TASKS.md`
- [x] Сделать Git checkpoint

## DATA-001

- [x] Создать `docs/PILOT_DATA_IMPORT_PLAN.md`
- [x] Описать порядок загрузки реальных данных
- [x] Создать папку `docs/import-templates/`
- [x] Подготовить описания шаблонов `students`, `groups`, `teachers`, `subjects`, `classrooms`, `admissions`, `curricula`, `teaching-load`, `schedule`
- [x] Для каждого шаблона указать обязательные поля, рекомендуемые поля, ключ обновления, пример строки и частые ошибки
- [x] Создать `docs/REAL_DATA_CHECKLIST.md`
- [x] Обновить `PROJECT_CONTEXT.md`
- [x] Обновить `ROADMAP.md`
- [x] Обновить `TASKS.md`
- [x] Сделать Git checkpoint

## DATA-002

- [x] Добавить в универсальный импорт тип `curricula`
- [x] Добавить в универсальный импорт тип `teaching-load`
- [x] Добавить в универсальный импорт тип `schedule`
- [x] Поддержать preview, mapping, validation и confirm import для трех новых типов
- [x] Добавить CSV-шаблоны для учебных планов, нагрузки и расписания
- [x] Обновить `docs/DATA_IMPORT.md`
- [x] Обновить `docs/import-templates/*.md`
- [x] Проверить `php artisan test` и `npm run build`
- [x] Сделать Git checkpoint

## EPIC-001

- [x] Создать `docs/ARCHITECTURE_REVIEW.md`
- [x] Создать `docs/TECHNICAL_DEBT.md`
- [x] Создать `docs/PERFORMANCE_REVIEW.md`
- [x] Создать `docs/SECURITY_REVIEW.md`
- [x] Создать `docs/REFACTOR_PLAN.md`
- [x] Оценить backend, frontend, сервисы, модели, импорт, Identity, QR, Mobile, Audit, Settings и Reference Data
- [x] Обновить `PROJECT_CONTEXT.md`
- [x] Обновить `ROADMAP.md`
- [x] Обновить `TASKS.md`
- [x] Сделать Git checkpoint

## REFACTOR-001

- [x] Зафиксировать текущее поведение тестами `UniversalImportApiTest`
- [x] Создать `ImportHandlerInterface`
- [x] Создать handler-ы для students, groups, teachers, subjects, classrooms, admissions, curricula, teaching-load, schedule
- [x] Оставить `UniversalImportService` фасадом/координатором
- [x] Сохранить API и поведение `/admin/import`
- [x] Проверить `php artisan test`
- [x] Проверить `npm run build`
- [x] Обновить документацию
- [x] Сделать Git checkpoint

## REFACTOR-002

- [x] Проанализировать `frontend/src/router/routes.js`
- [x] Перевести page-компоненты на lazy loading через dynamic import
- [x] Оставить критичные layout-компоненты синхронными
- [x] Проверить `npm run build`
- [x] Проверить маршруты `/dashboard`, `/students`, `/admissions`, `/admin/import`, `/admin/settings`, `/access/gate`, `/m/student`, `/legacy`
- [x] Зафиксировать результат по chunk warning
- [x] Обновить документацию
- [x] Сделать Git checkpoint

## REFACTOR-003

- [x] Создать `docs/PRODUCTION_SECURITY_CHECKLIST.md`
- [x] Создать `docs/PRODUCTION_DEPLOYMENT_READINESS.md`
- [x] Описать обязательные production security checks
- [x] Описать готовность, ручные проверки, блокеры и rollback plan
- [x] Обновить `docs/SECURITY_REVIEW.md`
- [x] Обновить `docs/REFACTOR_PLAN.md`
- [x] Обновить `PROJECT_CONTEXT.md`
- [x] Обновить `TASKS.md`
- [x] Проверить `php artisan test`
- [x] Проверить `npm run build`
- [x] Сделать Git checkpoint


## Versioning

- [x] Создать `frontend/public/version.json` как единый frontend-источник версии.
- [x] Создать `frontend/src/services/versionService.js` для чтения версии.
- [x] Показать версию, build hash и DEV/TEST/PROD в нижней части sidebar.
- [x] Добавить модальное окно `О системе`.
- [x] Создать `docs/VERSIONING.md`.

## UX Workspace

- [x] Создать `frontend/src/components/workspace/WorkspacePanel.vue`.
- [x] Унифицировать карточки студентов, преподавателей, групп, дисциплин и аудиторий.
- [x] Добавить единый блок быстрых действий.
- [x] Описать правила Workspace в `docs/WORKSPACE_GUIDELINES.md`.
- [x] Перевести inline-карточки учебных планов, нагрузки и выпуска на `WorkspacePanel`.
- [x] Перевести оставшиеся рабочие панели экзаменов, расписания, журнала, ФРДО, ФИС и отчетов по проходам на WorkspacePanel.
- [ ] При добавлении новых модулей сразу использовать `WorkspacePanel` для правых рабочих панелей.

## Role Dashboards

- [x] Создать `docs/ROLE_DASHBOARDS.md`.
- [x] Сохранить общий Dashboard как fallback.
- [x] Добавить административный Dashboard для `admin` и `director`.
- [x] Добавить преподавательский Dashboard для `teacher`.
- [ ] Добавить отдельные Dashboards для учебной части, приемной комиссии, студента и сотрудника проходной.


## ANALYTICS-001: Executive Dashboard

- [x] Создать `DashboardAnalyticsService` для агрегированной read-only аналитики.
- [x] Добавить API `/api/dashboard/analytics/executive`.
- [x] Обновить административный Dashboard для руководства.
- [x] Добавить KPI по контингенту, преподавателям, учебному процессу, проходной, приемной комиссии, ФРДО, ФИС и системе.
- [x] Добавить mini charts без сторонних библиотек.
- [x] Добавить блок “Что требует внимания”.
- [x] Создать `docs/EXECUTIVE_DASHBOARD.md`.
- [ ] Добавить полноценную 30-дневную динамику после проверки реальных данных.
- [ ] Добавить отдельный показатель конфликтов расписания в блок внимания.


## UX-003: Personal Dashboard Layout

- [x] Создать таблицу `dashboard_layouts`.
- [x] Добавить персональный API `/api/dashboard/layouts`.
- [x] Ограничить изменение layouts только владельцем.
- [x] Создать `PersonalDashboardLayout`.
- [x] Добавить режим настройки Dashboard: drag & drop, размер, скрытие, сохранение, отмена, сброс.
- [x] Подключить персональный layout к admin/director, teacher и general Dashboard.
- [x] Создать `docs/PERSONAL_DASHBOARD.md`.
- [ ] В будущих этапах добавить несколько именованных пользовательских профилей.

## QR-004: совместимость QR с физическим сканером

- [x] Проверить и зафиксировать содержимое QR.
- [x] Сохранить QR payload как чистый ASCII token.
- [x] Поддержать входные форматы `token` и `CP1:<token>`.
- [x] Перевести QR на Error Correction Level M, quiet zone 4 и черно-белый профиль.
- [x] Добавить PNG endpoint `format=png` рядом с SVG.
- [x] Улучшить `/identity/digital-passes`: крупный QR, PNG download, показ значения QR.
- [x] Улучшить `/m/student/pass` для чтения QR с телефона.
- [x] Добавить диагностику HID-сканера на `/access/gate`.
- [x] Добавить DEV-страницу `/access/scanner-test` только для admin.
- [x] Добавить backend-тесты token/CP1/CR/LF/PNG/SVG/no PII.

## QR-005: Mobile Camera Scanner

- [x] Создать маршрут `/access/mobile-scanner`.
- [x] Ограничить route ролями `admin` и `security`.
- [x] Использовать существующий `/api/access/scan`.
- [x] Добавить native `BarcodeDetector` и fallback `jsQR`.
- [x] Добавить запуск/переключение камеры и фонарик при поддержке.
- [x] Добавить allowed/denied результат, вибрацию, звук, паузу и повторный скан.
- [x] Добавить ручной ввод token.
- [x] Добавить пункт меню `Мобильный сканер` в `Идентификация`.
- [x] Создать `docs/MOBILE_ACCESS_SCANNER.md`.
- [x] Добавить тест доступа security/teacher/student к scan API.

## INFRA-006: HTTPS для DEV и мобильного сканера

- [x] Выбрать схему DEV HTTPS без Let's Encrypt для приватного IP.
- [x] Добавить отдельный Nginx HTTPS proxy на порт `5443`.
- [x] Сохранить HTTP DEV порты `5174` и `8001`.
- [x] Выпустить локальный CA и серверный сертификат для `192.168.34.104` и `college-dev.local`.
- [x] Исключить сертификаты и приватные ключи из git.
- [x] Настроить proxy для frontend, `/api`, `/storage` и WebSocket/HMR.
- [x] Перевести frontend API fallback на same-origin `/api` для защиты от mixed content.
- [x] Создать `docs/DEV_HTTPS.md` с инструкциями установки CA на Windows, Android и iOS.
- [x] Проверить `php artisan test`.
- [x] Проверить `npm run build`.
- [x] Проверить HTTPS `/dashboard`, `/access/mobile-scanner`, `/access/gate`, `/api/settings/public`, `/version.json`.

## ATTENDANCE-001A: Attendance Analysis Engine

- [x] Создать `AttendanceAnalysisService`.
- [x] Рассчитывать статусы преподавателей за текущий день.
- [x] Рассчитывать статусы студентов за текущий день.
- [x] Использовать `access_events` и `schedule_lessons` без изменения процессов проходной.
- [x] Добавить read-only API `/api/attendance/teachers/today`.
- [x] Добавить read-only API `/api/attendance/students/today`.
- [x] Создать страницу `/attendance` с таблицей.
- [x] Создать `docs/ATTENDANCE_ENGINE.md`.
- [x] Добавить feature-тесты аналитики посещаемости.

## ATTENDANCE-001B: Attendance Dashboard Integration

- [x] Добавить attendance aggregates в Executive Dashboard.
- [x] Добавить виджет “Преподаватели сегодня”.
- [x] Добавить виджет “Студенты сегодня”.
- [x] Расширить блок “Что требует внимания” отсутствиями и опозданиями.
- [x] Добавить быстрые переходы на `/attendance` с query-фильтрами.
- [x] Добавить фильтры `/attendance`: тип, статус, группа, преподаватель, период.
- [x] Добавить карточку выбранной записи справа.
- [x] Добавить настройки порогов `attendance.teacher_late_threshold_minutes` и `attendance.student_late_threshold_minutes`.
- [x] Добавить тесты API-фильтров и dashboard aggregates.

## ATTENDANCE-001C: История присутствия и учет времени

- [x] Расширить `AttendanceAnalysisService` исторической аналитикой за период.
- [x] Добавить расчет пар `IN -> OUT`, времени внутри, опозданий, ранних уходов, отсутствий и незакрытых сессий.
- [x] Поддержать переход через полночь через настройку `attendance.max_open_session_hours`.
- [x] Добавить API `/api/attendance/history`, `/api/attendance/person/{type}/{id}/summary`, `/api/attendance/person/{type}/{id}/days`.
- [x] Добавить CSV-экспорт исторического отчета.
- [x] Расширить `/attendance` режимами `Сегодня`, `Период`, `По человеку`.
- [x] Добавить вкладки карточки человека: `Сводка`, `По дням`, `Проходы`, `Расписание`.
- [x] Добавить CSS-полосу времени по дням без сторонних библиотек.
- [x] Добавить настройки `attendance.early_leave_threshold_minutes` и `attendance.max_open_session_hours`.
- [x] Покрыть тестами корректные пары, несколько пар, вход без выхода, выход без входа, ранний уход, опоздание, отсутствие, день без расписания, переход через полночь и CSV.

## RBAC-001: Permission Matrix and Fine-Grained Access Control

- [x] Расширить `permissions` полями `module`, `system`, `active`.
- [x] Создать Permission Resource и API `/api/admin/permissions`.
- [x] Добавить назначение permissions ролям.
- [x] Заполнить базовую матрицу из 61 permission.
- [x] Сохранить старые permissions для обратной совместимости.
- [x] Перевести API-проверки на точечные permissions через `EnsurePermission`.
- [x] Обновить Auth payload объединенными permissions всех ролей пользователя.
- [x] Перевести frontend routes/menu на новые permission-коды.
- [x] Добавить страницу `/admin/permissions`.
- [x] Добавить permission-aware быстрые действия Dashboard.
- [x] Добавить RBAC-тесты: 403 без permission, 200 с permission, teacher/student/security/director сценарии.
- [x] RBAC-001.1: скрыть action-кнопки внутри основных CRUD-страниц по create/update/delete/export/import permissions.
- [ ] Следующим этапом усилить ownership-фильтры для teacher/student: только свои группы, расписание, журнал, экзамены.

## RBAC-001.1: Permission-aware CRUD UI

- [x] Создать `usePermissions()` для `hasPermission`, `hasAnyPermission`, `hasAllPermissions`.
- [x] Создать `PermissionGuard.vue` для условного отображения UI.
- [x] Добавить `/forbidden` и route guard для single/any/all permissions.
- [x] Закрыть action-кнопки и обработчики в Students, Groups, Teachers, Subjects, Classrooms.
- [x] Закрыть action-кнопки и обработчики в Admissions, Curricula, Teaching Load, Exams, Graduation.
- [x] Закрыть действия ФРДО, ФИС, Digital Passes.
- [x] Закрыть системные действия Users, Roles, Permissions, Settings, Reference, Import, Data Management.
- [x] Проверить `npm run build`.
- [ ] Frontend unit/integration tests не добавлены: в текущем frontend нет test runner; проверка выполнена через build и API smoke.


## PERSON-001: Unified Person Foundation

- [x] Создать таблицу `people`.
- [x] Добавить nullable `person_id` в профили и DigitalIdentity.
- [x] Создать модель Person и связи с профилями.
- [x] Создать PersonService.
- [x] Создать `php artisan person:link-existing --dry-run/--apply`.
- [x] Добавить read-only API `/api/people`.
- [x] Добавить frontend `/people`.
- [x] Добавить permissions `people.view/create/update/link/merge`.
- [x] Добавить документацию `docs/PERSON_MODEL.md`.
- [x] Сохранить обратную совместимость существующих API.
- [ ] Реализовать ручной UI для проверки дублей и merge на отдельном этапе.

## FIS-IMPORT-001

- [x] Добавлен специализированный connector ФИС ГИА и Приема для заявлений.
- [x] Поддержан dry-run с маскированием ПДн.
- [x] Подготовлен apply с блокировкой при критических ошибках, дублях и несопоставленных конкурсах.
- [x] Добавлена документация `docs/FIS_ADMISSIONS_IMPORT.md`.

## BULK-001

- [x] Добавить backend preview/apply endpoints для Admissions и Students.
- [x] Добавить RBAC permissions для массовых операций.
- [x] Добавить Audit Log для apply.
- [x] Добавить выбор строк и bulk-панель в `/admissions` и `/students`.
- [x] Добавить документацию `docs/BULK_OPERATIONS.md`.
- [x] Проверить `php artisan test` и `npm run build`.


## ADM-DOCS-001: Applicant Documents Registry

- [x] Добавить Reference Data каталог `applicant_document_types`.
- [x] Расширить `applicant_application_documents` и добавить `applicant_document_files`.
- [x] Добавить API приема, загрузки, проверки, отклонения, скачивания и удаления файлов.
- [x] Пересчитать комплектность по обязательным типам документов.
- [x] Добавить вкладку документов в карточку заявления `/admissions`.
- [x] Добавить permissions и audit.
- [x] Добавить sync-команду legacy registry.
- [x] Добавить документацию `docs/APPLICANT_DOCUMENTS.md`.
- [ ] Расширить frontend bulk-панель действиями по типу документа отдельной UX-задачей.

## ST-001A: Curriculum Engine foundation

- [x] Проанализировать текущие Curricula, Subjects, Groups, Teaching Load, Exams.
- [x] Создать `docs/CURRICULUM_DOMAIN.md`.
- [x] Добавить `curriculum_subjects`.
- [x] Добавить `control_types` в Reference Data.
- [x] Добавить связь `groups.curriculum_id`.
- [x] Добавить API subjects/semesters/summary и CRUD строк плана.
- [x] Добавить `CurriculumEngineService`.
- [x] Добавить вкладки карточки учебного плана на `/curricula`.
- [x] Добавить permissions `curricula.subjects.*`.
- [x] Добавить audit и тесты.
- [x] Проверить `php artisan test` и `npm run build`.

## ST-001B: Generate teaching load from curriculum

- [x] Расширить `teaching_loads` и `teaching_load_items` для связи с Curriculum Engine.
- [x] Создать `TeachingLoadGenerationService`.
- [x] Добавить preview/apply API.
- [x] Добавить coverage API.
- [x] Добавить ручное и массовое назначение преподавателя.
- [x] Добавить фильтры и preview-диалог на `/teaching-load`.
- [x] Добавить быстрое действие из `/curricula`.
- [x] Добавить RBAC permissions и Audit Log.
- [x] Добавить тесты и документацию `docs/TEACHING_LOAD_ENGINE.md`.

## ST-002A: Schedule Engine Foundation

- [x] Создана модель `schedule_entries`.
- [x] Добавлены шаблоны расписания как foundation.
- [x] Добавлен ScheduleEngineService.
- [x] Добавлены preview/validate/apply endpoints.
- [x] Добавлены конфликты и покрытие нагрузки.
- [x] `/schedule` получил создание занятия через preview.
- [x] Документация обновлена.

## ST-002B: Visual Schedule Editor

- [x] Добавлен режим «Редактор недели».
- [x] Добавлен drag & drop preview для переноса занятий.
- [x] Добавлен режим «Шаблоны».
- [x] Добавлено MVP-создание и применение шаблонов.
- [x] Добавлены панели конфликтов и покрытия нагрузки.
- [x] Сохранены существующие режимы расписания.
- [x] Проверены backend tests и frontend build.

## INFRA-007

- [x] Add installation distribution structure under `installer/`.
- [x] Add lifecycle scripts for install, update, backup, restore, uninstall and check.
- [x] Add release archive builder under `scripts/release/build-release.sh`.
- [x] Add production installation, update, backup/restore and release documentation.
- [x] Add health endpoints for installer checks.

## ST-003A

- [x] Add schedule-linked journal lesson model.
- [x] Add attendance, grades and private lesson files for journal lessons.
- [x] Add JournalService and journal lesson API.
- [x] Add RBAC permissions for lesson lifecycle, attendance, grades, files and signing.
- [x] Update `/journal` to open lessons from Schedule Engine and edit lesson details.

## ST-003B

- [x] Добавить режимы рабочего места преподавателя на `/journal`.
- [x] Добавить таблицу студентов выбранного занятия.
- [x] Добавить bulk-посещаемость и сохранение оценок без перезагрузки.
- [x] Добавить preview посещаемости по проходной.
- [x] Добавить материалы занятия, завершение, подпись и reopen.
- [x] Добавить контрольный режим для учебной части.
- [x] Обновить Dashboard преподавателя.
- [x] Добавить тесты, документацию и CSV exports.

## UAT-003

- [x] Добавить `/admin/uat`.
- [x] Добавить UAT test runs/results и feedback registry.
- [x] Добавить сценарии по ролям и UAT-аккаунты без паролей.
- [x] Добавить кнопку `Сообщить о проблеме`.
- [x] Добавить private screenshots и CSV exports.
- [x] Создать role guides и feedback process docs.

## HR-001A: Employee and HR Foundation

Статус: выполнено.

- Добавлены таблицы и модели кадрового контура.
- Добавлены API и frontend `/hr/employees`, `/hr/departments`, `/hr/positions`.
- Добавлены HR permissions и роль `hr`.
- Добавлен импорт сотрудников через `/admin/import`.
- Schedule Engine показывает warning при недоступности преподавателя по кадровым данным.
- Добавлены документы `docs/HR_DOMAIN.md` и `docs/HR_USER_GUIDE.md`.

## HR-001B: HR absence calendar and teacher replacements

Статус: выполнено.

- Добавлен `/hr/calendar`.
- Добавлен lifecycle кадрового периода.
- Добавлен preview/apply/cancel для отсутствий.
- Добавлены affected lessons, replacement candidates, replacement preview/apply.
- Добавлены HR events, RBAC, Audit, CSV report.
- Добавлена документация `HR_ABSENCE_CALENDAR.md` и `HR_REPLACEMENTS.md`.

## INFRA-008

- [x] Validate autonomous installer on clean UAT server.
- [x] Verify health, API smoke, backup/restore, update, rollback, HTTPS smoke, uninstall and reinstall.
- [x] Document UAT server and acceptance results.
- [ ] Improve repeated-install message to mention existing installation in addition to occupied ports.
## GITHUB-001

- [x] Verify GitHub CLI authorization.
- [x] Harden `.gitignore` for secrets, imports, dumps, backups, certificates and runtime files.
- [x] Add repository README, vision, security, support, governance, contributing and changelog documents.
- [x] Add CI workflow and GitHub issue/PR templates.
- [x] Run pre-push secret audit with git scans and gitleaks.
- [x] Create private GitHub repository and push `develop` / `main`.
- [x] Create Release 0.8.0-rc2 and initial Issues.
- [x] Create GitHub Project after refreshing gh scopes: `CollegePortal Roadmap`.

## CI-001

- [x] Reproduced `JournalEngineApiTest` teacher scope failure locally in the backend container.
- [x] Identified date-dependent `mode=week` fixture as the root cause.
- [x] Froze Journal Engine test clock and strengthened teacher/study RBAC assertions.
- [x] Verified targeted test 10 consecutive times.
- [x] Verified full backend suite and frontend build locally.
- [x] Checked branch protection availability; GitHub private repo plan blocks it with HTTP 403.

## BUG-009

- [x] Localize `/admin/users` validation messages for user-facing errors.
- [x] Return `422` payload with `message` and field-level `errors` for user form validation.
- [x] Show user create/edit validation errors inline inside the modal.
- [x] Keep global banners for non-field and unexpected errors only.
- [x] Add backend coverage for required name/email/password/role, invalid email, duplicate email, missing Person and edit without password change.


## FIS-API-001

- [x] FIS-API-001: создать foundation официального outbound-коннектора ФИС без production-отправки
- [ ] Получить официальные WSDL/XSD/spec материалы через разрешенный доступ ФЦТ
- [ ] Подключить реальные SOAP methods строго по спецификации
- [ ] Провести TEST отправку только после credentials и доступа ЗКСПД


## FIS-API-001.1

- [x] Проверить route/TCP/curl к TEST endpoint с DEV host и backend container
- [x] Подготовить `scripts/fis/check-zkspd-access.sh`
- [x] Подготовить Gateway Agent skeleton для узла ЗКСПД
- [ ] Получить официальные документы ФЦТ 4.9 и сформировать SHA-256 manifest
- [ ] Реализовать точный SOAP/XML контракт строго по WSDL/XSD
- [ ] Выполнить первую TEST-отправку после credentials и доступа ЗКСПД

## FIS-GATEWAY-001

- [x] FIS-GATEWAY-001: add ViPNet Gateway Agent source, HMAC transport, diagnostics UI and installation docs
- [ ] Build and run the agent on real Windows 7 ViPNet workstation
- [ ] Copy official WSDL/XSD/DISCO files into private storage and regenerate manifest
- [ ] Confirm FIS authentication with one controlled TEST read-only call

## REPO-SYNC-001

- [x] REPO-SYNC-001: merge FIS gateway PR, sync Linux DEV, inventory repository copies, add sync helpers and docs

## INTEGRATION-HUB-001

CollegePortal Gateway foundation added: FIS Gateway Agent is generalized into a modular Windows service architecture for protected integrations. FIS remains the only implemented adapter; future FRDO/Moodle/LDAP/MAX/Telegram/Email adapters are planned. Windows repo path is `C:\!Projects\CollegePortal`; ViPNet installation remains a separate task.
