# CollegePortal: контекст проекта для разработчика

Документ предназначен для быстрой передачи контекста новому разработчику. Он описывает назначение системы, стек, структуру, архитектуру, базу данных, реализованные модули, текущие задачи, roadmap и запуск.

## Назначение проекта

CollegePortal — веб-портал для колледжа искусств. Цель проекта — создать единую рабочую систему для учебной части, приемной комиссии, преподавателей, кураторов, студентов и администрации.

Система должна закрывать основные процессы колледжа:

- учет студентов;
- учет групп;
- учет преподавателей;
- учет дисциплин;
- учет аудиторий;
- расписание;
- электронный журнал;
- посещаемость;
- оценки;
- отчеты;
- приемная комиссия;
- учебные планы;
- нагрузка преподавателей;
- экзамены и ГИА;
- выпускники, дипломы и приложения к диплому;
- подготовка данных ФРДО;
- подготовка данных ФИС ГИА / ФИС Приема;
- публичный раздел для абитуриентов;
- будущие интеграции с Moodle, LDAP/Active Directory, ФРДО и ФИС ГИА/Приема.

Текущий фокус MVP: рабочий внутренний портал с авторизацией, справочниками, расписанием, журналом, отчетами, базовой приемной комиссией и подготовкой структуры под будущие государственные и LMS-интеграции.

## Стек технологий

Backend:

- Laravel 12;
- PHP 8.4;
- REST API;
- Eloquent ORM;
- Form Request Validation;
- API Resources;
- Service Layer для бизнес-логики и CSV-обмена;
- DTO там, где входные данные сложнее обычного CRUD.

Frontend:

- Vue 3;
- Vite;
- Quasar как основная UI-платформа для нового GUI;
- Tailwind CSS для кастомизации, плотного рабочего интерфейса и фирменного стиля;
- Lucide Icons как основной набор иконок;
- JavaScript;
- Pinia;
- Vue Router;
- старый GUI сохранен в `frontend/src/App.vue` на время миграции;
- новый Quasar-каркас подключен через `frontend/src/app/App.vue`;
- временный маршрут `/legacy` открывает старый интерфейс до полной миграции разделов;
- Design System нового GUI описан в `docs/DESIGN_SYSTEM.md`;
- UI Foundation нового GUI описан в `docs/UI_FOUNDATION.md`;
- демонстрационная страница UI-компонентов доступна администратору по маршруту `/system/ui-foundation`;
- новые стили находятся в `frontend/src/styles/main.css`;
- брендовые favicon/PWA-иконки собраны из официального черно-белого логотипа СККИ `docs/logo/Logo_SKKI_чб.jpg`; frontend-копия находится в `frontend/public/brand/logo-skki-bw.jpg`, favicon/PWA-файлы — в `frontend/public/favicon.svg`, `frontend/public/favicon.ico`, `frontend/public/apple-touch-icon.png`, `frontend/public/icons/`;
- окружение frontend определяется через `VITE_APP_ENV`; визуальная индикация DEV/TEST/PROD описана в `docs/ENVIRONMENTS.md`;
- Quasar подключен с русской локалью `quasar/lang/ru`;
- старые стили находятся в `frontend/src/style.css` и будут удаляться только после полного переноса разделов.

Архитектурное решение по GUI зафиксировано в `docs/ARCHITECTURE_DECISIONS.md`, ADR-001: использовать Quasar + Tailwind CSS + Lucide Icons.

База данных:

- PostgreSQL 17.

Инфраструктура:

- Docker;
- Docker Compose;
- Nginx;
- Ubuntu Server 24.04;
- Hyper-V VM для текущего стенда.

Текущие окружения:

- DEV: `/srv/college-dev`, frontend `http://192.168.34.104:5174`, API `http://192.168.34.104:8001/api`, PostgreSQL `5433`;
- PROD: `/home/andale/college_portal`, frontend `http://192.168.34.104:5173`, API `http://192.168.34.104:8080/api`, PostgreSQL `5432`;
- тестовый администратор: `admin@college-portal.local` / `password`.

Окружения на Ubuntu:

- PROD: `/home/andale/college_portal`, порты `5173` / `8080` / `5432`;
- DEV: `/srv/college-dev`, порты `5174` / `8001` / `5433`;
- DEV использует отдельные контейнеры `college_dev_*` и отдельную базу `college_portal_dev`;
- инструкция по запуску, остановке, build и логам описана в `docs/DEV_ENVIRONMENT.md`.
- безопасный процесс переноса DEV -> PROD описан в `docs/DEPLOYMENT.md`; реальные изменения PROD требуют отдельного подтверждения.
- Git workflow для DEV/PROD описан в `docs/GIT_WORKFLOW.md`; INFRA-004 инициализирует Git в `/srv/college-dev` на ветке `develop` без remote и без секретов/runtime-файлов.

## Структура каталогов

Корень проекта:

- `AGENTS.md` — инструкции для Codex и правила разработки.
- `README.md` — краткое описание и быстрый старт.
- `PROJECT_CONTEXT.md` — этот файл, сводный контекст проекта.
- `PROJECT_BRIEF.md` — общее назначение проекта.
- `MVP_SPEC.md` — техническое задание MVP.
- `DATABASE_SCHEMA_MVP.md` — схема базы данных MVP.
- `ROADMAP.md` — план этапов разработки.
- `TASKS.md` — чек-лист задач и ближайший backlog.
- `SECURITY_NOTES.md` — заметки по безопасности.
- `EXTERNAL_SERVICES_NOTES.md` — заметки по ФРДО, ФИС ГИА/Приема и материалам для абитуриентов.
- `docs/ARCHITECTURE_DOCUMENTATION.md` — единое оглавление архитектурной и проектной документации.
- `docs/DESIGN_SYSTEM.md` — единая дизайн-система CollegePortal: логотип, цвета, типографика, компоненты и адаптивность.
- `docs/PRODUCT_VISION.md` — продуктовая цель, пользователи, границы MVP и критерии успеха CollegePortal.
- `docs/PHILOSOPHY.md` — принципы развития системы, рабочего интерфейса, данных и безопасности.
- `docs/ARCHITECTURE_DECISIONS.md` — зафиксированные архитектурные решения, включая выбор frontend UI-платформы.
- `docs/ACCESS_CONTROL_CONCEPT.md` — концепция Person, Digital Identity, QR-пропусков и будущего модуля присутствия.
- `docs/IDENTITY_DOMAIN.md` — архитектура Identity Domain: Person, Digital Identity, роли, учетные данные, QR/Mobile Pass, Access Control, Authentication и Authorization.
- `docs/DIGITAL_PASSES.md` — MVP цифровых QR-пропусков: таблица `digital_identities`, API, SVG QR и правила безопасности token.
- `docs/FRDO.md` — MVP подготовки данных ФРДО без реальной отправки: проверки полноты, выгрузка и будущая интеграция.
- `docs/FIS.md` — MVP подготовки данных ФИС ГИА / ФИС Приема без реальной отправки.
- `docs/DOMAIN_MODEL.md` — доменная модель CollegePortal Platform: Identity, Academic, Learning, Administration, Integrations, Analytics.
- `docs/LAYOUT_GUIDELINES.md` — правила раскладки рабочих страниц, включая таблицу слева и карточку справа без overlay.
- `docs/RESPONSIVE_WORKSPACE.md` — правила адаптивного рабочего пространства, breakpoints, плотность и workspace-режимы.
- `docs/DEV_ENVIRONMENT.md` — разделение PROD/DEV на Ubuntu, порты и команды обслуживания DEV-окружения.
- `docs/DEPLOYMENT.md` — безопасный процесс проверки DEV, backup PROD, деплоя и rollback.
- `docs/GIT_WORKFLOW.md` — рекомендуемые ветки `main`/`develop`/`feature/*`, формат коммитов и работа DEV/PROD через Git.
- `docker-compose.yml` — локальная инфраструктура.
- `.env.example` — пример переменных окружения.

Backend:

- `backend/app/Models` — Eloquent-модели.
- `backend/app/Http/Controllers/Api` — REST API controllers.
- `backend/app/Http/Requests` — валидация входящих запросов.
- `backend/app/Http/Resources` — API Resources.
- `backend/app/Services` — бизнес-логика и CSV-сервисы.
- `backend/app/DTO` — DTO для сложных входных данных.
- `backend/app/Http/Middleware` — middleware авторизации и прав.
- `backend/routes/api.php` — API routes.
- `backend/database/migrations` — миграции.
- `backend/database/seeders` — сидеры ролей, прав и тестовых данных.
- `backend/tests/Feature` — feature-тесты API.

Frontend:

- `frontend/src/App.vue` — старый монолитный GUI, сохранен как legacy до полной миграции.
- `frontend/src/app/App.vue` — root-компонент нового Quasar GUI.
- `frontend/src/layouts` — `AppLayout`, `AuthLayout`, `PublicLayout`.
- `frontend/src/pages` — страницы нового GUI; перенесены авторизация, системная страница UI Foundation и разделы `students`, `groups`, `teachers`, `subjects`, `classrooms`, `schedule`, `journal`, `admissions`, `curricula`, `teaching-load`, `exams`, `graduation`, `frdo`, `fis`, остальные разделы постепенно переносятся из legacy.
- `frontend/src/pages/legacy/LegacyPage.vue` — временный доступ к старому GUI по маршруту `/legacy`.
- `frontend/src/pages/system/UiFoundationPage.vue` — демонстрация базовых UI-компонентов нового GUI.
- `frontend/src/router` — Vue Router и маршруты нового GUI.
- `frontend/src/stores` — Pinia stores: `auth`, `students`, `groups`, `teachers`, `subjects`, `classrooms`, `schedule`, `journal`, `admissions`, `curricula`, `teachingLoad`, `exams`, `graduation`, `frdo`, `fis`, `search`, `workspace`.
- `frontend/src/styles` — стили нового GUI.
- `frontend/src/pages/fis/FisPage.vue` — новый раздел подготовки данных ФИС ГИА / ФИС Приема: пакеты, записи, ошибки проверки, CSV/JSON export.
- `frontend/src/pages/frdo/FrdoPage.vue` — новый раздел подготовки данных ФРДО: пакеты, записи, ошибки проверки, CSV/JSON export.
- `frontend/src/pages/graduation/GraduationPage.vue` — новый раздел выпускников и дипломов с вкладками карточки.
- `frontend/src/pages/exams/ExamsPage.vue` — новый раздел экзаменов и ГИА с результатами студентов.
- `frontend/src/services/api.js` — клиент API, токен авторизации, обработка ошибок.
- `frontend/src/services/tableSettings.js` — общий helper для сохранения пользовательских настроек таблиц.
- `frontend/src/services/layoutService.js` — общий сервис breakpoints, размера viewport, mobile/ultrawide-признаков.
- `frontend/src/style.css` — стили старого legacy-интерфейса.
- `frontend/src/main.js` — входная точка Vue.

Инфраструктура:

- `infra/nginx/default.conf` — Nginx-конфигурация.
- `scripts/` — скрипты инициализации Laravel/Vue-проекта для Windows и Linux.
- `docs/external-services/` — справочные материалы для будущих интеграций.

## Архитектура

### Общая схема

Проект построен как SPA + REST API:

- Vue frontend обращается к Laravel API.
- Laravel API работает через Nginx.
- Backend хранит данные в PostgreSQL.
- Авторизация выполняется через API token.
- Доступ к разделам ограничивается ролями и permissions.

### Доменная модель

Высокоуровневая доменная модель описана в `docs/DOMAIN_MODEL.md`.

CollegePortal Platform рассматривается как набор связанных доменов:

- `Identity` — люди, пользователи, роли, права и будущая цифровая идентичность;
- `Academic` — студенты, группы, преподаватели, дисциплины, аудитории, специальности и образовательные программы;
- `Learning` — расписание, журнал, посещаемость, оценки и учебные занятия;
- `Administration` — приемная комиссия, документы, приказы, движение контингента, присутствие и доступ;
- `Integrations` — Moodle, ФРДО, ФИС ГИА/Приема, LDAP/AD и уведомления;
- `Analytics` — отчеты, Dashboard, показатели и будущая аналитика.

Документ фиксирует, какие домены уже реализованы частично, какие находятся в разработке, а какие запланированы. Это архитектурный ориентир для будущих модулей: новые разделы нужно проектировать в рамках доменов, не смешивая учебный процесс, администрирование, интеграции и аналитику в одном слое.

### Person, Digital Identity и присутствие

В QR-001 реализован MVP цифровых пропусков:

- backend-таблица `digital_identities`;
- API `GET /api/digital-identities`, `POST /api/digital-identities/issue`, `POST /api/digital-identities/{id}/revoke`, `GET /api/digital-identities/{id}/qr`;
- QR содержит только token, без ФИО и персональных данных;
- frontend-раздел `/identity/digital-passes`;
- поддержаны владельцы `student` и `teacher`;
- выпуск и отзыв цифровых пропусков через существующую авторизацию.


Архитектурная концепция будущего модуля описана в `docs/ACCESS_CONTROL_CONCEPT.md`.

ARCH-001: Identity Domain Architecture зафиксировал отдельный домен `Identity`. Он станет основой для `Person`, `Digital Identity`, QR-пропусков, мобильного кабинета, авторизации, проходной, будущих RFID/NFC и Face ID. Документ находится в `docs/IDENTITY_DOMAIN.md`.

Ключевая идея: в будущем ввести центральную сущность `Person`, которая объединит физическое лицо и его роли: `Student`, `Teacher`, `Employee`, `Applicant`, `Guest`, `Alumni`. Поверх `Person` планируется `Digital Identity`: QR-код, мобильный QR, печатный QR, фото, срок действия и статус.

Эта концепция нужна для будущего модуля “Присутствие и доступ”: проходная, вход/выход, USB QR-сканер как HID-клавиатура, отчеты по студентам, преподавателям, сотрудникам и гостям. На текущем этапе это только архитектурная проработка: backend, frontend, БД и API не изменены.

### Backend-подход

Основные правила:

- каждый модуль имеет модель, controller, request-классы и resource-класс;
- валидация не пишется прямо в controller, а выносится в Form Request;
- API-ответы проходят через Resource-классы;
- повторяемая бизнес-логика и CSV-обмен вынесены в Service Layer;
- расписание использует DTO и сервис проверки конфликтов;
- приемная комиссия использует отдельные сервисы для документов, событий и CSV;
- ошибки API для маршрутов `/api/*` возвращаются как JSON.

Ключевые backend-модули:

- `FisPackageController` — подготовка пакетов ФИС Приема и ФИС ГИА, проверка полноты, CSV/JSON export, статусы; таблицы `fis_packages`, `fis_records`, `fis_validation_errors`.
- `FrdoPackageController` — подготовка пакетов ФРДО, проверка полноты, CSV/JSON export, статусы; таблицы `frdo_packages`, `frdo_records`, `frdo_validation_errors`.
- `GraduateController` — выпускники, дипломы, приложения к диплому, CSV import/export; таблицы `graduates`, `diplomas`, `diploma_supplements`.
- `ExamController` — экзамены, зачеты, ГИА, результаты студентов, CSV import/export; таблицы `exams` и `exam_results`.
- `TeachingLoadController` — нагрузка преподавателей и строки нагрузки.
- `CurriculumController` — учебные планы и строки учебного плана.

- `AuthController` — вход, профиль, выход.
- `StudentController` — студенты, импорт/экспорт.
- `GroupController` — группы, импорт/экспорт.
- `TeacherController` — преподаватели, импорт/экспорт.
- `SubjectController` — дисциплины, импорт/экспорт.
- `ClassroomController` — аудитории, импорт/экспорт.
- `ScheduleLessonController` — расписание и конфликты.
- `AttendanceController` — посещаемость.
- `GradeController` — оценки.
- `ReportController` — отчеты по посещаемости и оценкам.
- `SpecialtyController` — специальности.
- `EducationProgramController` — образовательные программы.
- `ApplicantApplicationController` — заявления абитуриентов, документы, зачисление, CSV.

### Frontend-подход

Текущий frontend находится в переходном состоянии: legacy-интерфейс сохранен в `frontend/src/App.vue`, новый рабочий интерфейс развивается в Quasar-структуре `frontend/src/app`, `frontend/src/layouts`, `frontend/src/pages`, `frontend/src/components/ui`, `frontend/src/stores`, `frontend/src/router`, `frontend/src/styles`.

Legacy-разделы:

- публичный вход и публичный раздел «Абитуриенту»;
- панель управления;
- студенты;
- группы;
- специальности;
- образовательные программы;
- заявления;
- преподаватели;
- дисциплины;
- аудитории;
- расписание;
- журнал;
- отчеты.

Новый `/dashboard`:

- `frontend/src/pages/dashboard/DashboardPage.vue` — виджетная главная страница после входа;
- `frontend/src/pages/dashboard/widgets/StatsWidget.vue` — показатели по студентам, группам, преподавателям и занятиям сегодня;
- `frontend/src/pages/dashboard/widgets/QuickActionsWidget.vue` — быстрые переходы к студентам, группам, расписанию и журналу;
- `frontend/src/pages/dashboard/widgets/RecentActivityWidget.vue` — последние действия, пока mock-данные;
- `frontend/src/pages/dashboard/widgets/NotificationsWidget.vue` — уведомления, пока mock-данные;
- `frontend/src/pages/dashboard/widgets/TasksWidget.vue` — задачи пользователя, пока mock-данные;
- документирование Dashboard находится в `docs/DASHBOARD.md`.

В новом Quasar GUI уже перенесен раздел «Студенты»:

- `frontend/src/pages/students/StudentsPage.vue` — список, фильтры, карточка, импорт/экспорт и действия;
- `frontend/src/pages/students/StudentFilters.vue` — фильтры по ФИО, группе и статусу;
- `frontend/src/pages/students/StudentFormPanel.vue` — создание и редактирование студента;
- `frontend/src/pages/students/StudentDetailsPanel.vue` — карточка выбранного студента с вкладками, быстрыми показателями, секциями обучения, контактов, посещаемости, оценок и заделом под документы/ФРДО;
- `frontend/src/stores/students.js` — загрузка данных, фильтры, CRUD, импорт/экспорт CSV, краткая сводка посещаемости и оценок по выбранному студенту.
- `frontend/src/services/tableSettings.js` — сохранение выбранного размера страницы таблицы, сейчас используется ключ `collegePortal.students.rowsPerPage`.

Раздел «Студенты» считается эталонным модулем нового GUI. Остальные CRUD-разделы нужно переносить по его образцу: плотная таблица, фильтры с активными чипами, карточка справа, уведомления после действий, компактные строковые действия и сохранение legacy-доступа до окончания миграции.

Для страниц с таблицей и правой карточкой действует регламент `docs/LAYOUT_GUIDELINES.md`: таблица и карточка должны быть отдельными grid-колонками, правая карточка держится в диапазоне 380-420 px, таблица занимает оставшееся место и прокручивается внутри своей области. На ширине около 1366 px широкие таблицы переносят карточку под таблицу, чтобы последние колонки оставались читаемыми.

Responsive Workspace Foundation описан в `docs/RESPONSIVE_WORKSPACE.md`:

- `frontend/src/services/layoutService.js` определяет breakpoint, ширину/высоту viewport, mobile и ultrawide;
- `frontend/src/stores/workspace.js` хранит ширину бокового меню, состояние свернутого меню, отображение правой панели, плотность интерфейса и режим workspace;
- настройки workspace сохраняются в `localStorage` по ключу `collegePortal.workspace.settings`;
- `AppLayout` и `AppPage` прокидывают workspace-классы, чтобы Dashboard, Students, Groups, Schedule и Journal адаптировались единообразно.

В новом Quasar GUI перенесен раздел «Преподаватели»:

- страница `frontend/src/pages/teachers/TeachersPage.vue`;
- store `frontend/src/stores/teachers.js`;
- компоненты `TeacherFilters.vue`, `TeacherFormPanel.vue`, `TeacherDetailsPanel.vue`;
- функции: список, поиск, фильтры по статусу и отделению, создание, редактирование, удаление, импорт/экспорт CSV, карточка преподавателя;
- маршрут `/teachers` открывает новый раздел;
- глобальный поиск ищет преподавателей вместе со студентами, группами, дисциплинами и аудиториями.

В новом Quasar GUI перенесен раздел «Дисциплины»:

- страница `frontend/src/pages/subjects/SubjectsPage.vue`;
- store `frontend/src/stores/subjects.js`;
- компоненты `SubjectFilters.vue`, `SubjectFormPanel.vue`, `SubjectDetailsPanel.vue`;
- функции: список, поиск по названию/коду/описанию, фильтры по отделению и преподавателю, создание, редактирование, удаление, импорт/экспорт CSV, карточка дисциплины справа;
- карточка показывает название, код, статус, отделение, преподавателей, связанные занятия и быстрые переходы к расписанию, журналу и преподавателям;
- размер страницы таблицы сохраняется по ключу `collegePortal.subjects.rowsPerPage`;
- маршрут `/subjects` открывает новый раздел;
- глобальный поиск ищет дисциплины через `frontend/src/services/searchService.js`.

В новом Quasar GUI перенесен раздел «Аудитории»:

- страница `frontend/src/pages/classrooms/ClassroomsPage.vue`;
- store `frontend/src/stores/classrooms.js`;
- компоненты `ClassroomFilters.vue`, `ClassroomFormPanel.vue`, `ClassroomDetailsPanel.vue`;
- функции: список, поиск по номеру/корпусу/типу/описанию, фильтры по корпусу и типу, создание, редактирование, удаление, импорт/экспорт CSV, карточка аудитории справа;
- карточка показывает номер, корпус, этаж, тип, вместимость, статус, связанные занятия и быстрые переходы к расписанию и журналу;
- размер страницы таблицы сохраняется по ключу `collegePortal.classrooms.rowsPerPage`;
- маршрут `/classrooms` открывает новый раздел;
- глобальный поиск ищет аудитории через `frontend/src/services/searchService.js`.


В новом Quasar GUI перенесен раздел «Приемная комиссия»:

- страница `frontend/src/pages/admissions/AdmissionsPage.vue`;
- store `frontend/src/stores/admissions.js`;
- компоненты `AdmissionFilters.vue`, `AdmissionFormPanel.vue`, `AdmissionDetailsPanel.vue`;
- функции: список заявлений, поиск по ФИО/контактам, фильтры по статусу, специальности, программе, комплектности документов и дате подачи, создание, редактирование, удаление, импорт/экспорт CSV;
- карточка заявления справа показывает ФИО, контакты, специальность, программу, статус, документы, события/историю и комментарий приемной комиссии;
- быстрые очереди показывают новые, неполный комплект, готовые к зачислению, зачисленные и отклоненные заявления;
- через существующий API доступны отметки документов и зачисление абитуриента в студенты при полном комплекте документов;
- размер страницы таблицы сохраняется по ключу `collegePortal.admissions.rowsPerPage`;
- маршрут `/admissions` открывает новый раздел;
- глобальный поиск ищет заявления абитуриентов через `frontend/src/services/searchService.js`.

В новом Quasar GUI также перенесен раздел «Группы»:

- `frontend/src/pages/groups/GroupsPage.vue` — список групп, фильтры, карточка, импорт/экспорт и действия;
- `frontend/src/pages/groups/GroupFilters.vue` — фильтры по поиску, курсу и образовательной программе;
- `frontend/src/pages/groups/GroupFormPanel.vue` — создание и редактирование группы;
- `frontend/src/pages/groups/GroupDetailsPanel.vue` — карточка выбранной группы;
- `frontend/src/stores/groups.js` — загрузка групп, программ, преподавателей, CRUD, импорт/экспорт CSV и frontend-фильтрация;
- размер страницы таблицы групп сохраняется по ключу `collegePortal.groups.rowsPerPage`.

В новом Quasar GUI перенесен раздел «Расписание»:

- `frontend/src/pages/schedule/SchedulePage.vue` — рабочая страница расписания с представлениями «День», «Неделя», «Преподаватель», «Группа», «Аудитория»;
- `frontend/src/pages/schedule/ScheduleFilters.vue` — фильтры по учебному году, семестру, группе, преподавателю, аудитории и дисциплине;
- `frontend/src/pages/schedule/ScheduleDetailsPanel.vue` — карточка выбранного занятия со ссылками на журнал, группу, преподавателя и аудиторию;
- `frontend/src/stores/schedule.js` — загрузка занятий и справочников через существующие API, frontend-фильтр учебного года/семестра, выбор занятия и предупреждения о возможных конфликтах в загруженной выборке.

Раздел `/schedule` использует существующий API `schedule-lessons`; backend, БД и API не менялись. Legacy-доступ остается доступен по `/legacy`.

В новом Quasar GUI создан read-only раздел «Журнал»:

- `frontend/src/pages/journal/JournalPage.vue` — матрица электронного журнала: строки студенты, колонки занятия, ячейки показывают существующие оценки и отметки посещаемости;
- `frontend/src/pages/journal/JournalFilters.vue` — фильтры по учебному году, семестру, группе, дисциплине, преподавателю и дате с сохранением в `localStorage`;
- `frontend/src/pages/journal/JournalLessonPanel.vue` — карточка выбранного занятия с дисциплиной, преподавателем, группой, аудиторией, датой, временем, темой, домашним заданием и быстрыми переходами;
- `frontend/src/stores/journal.js` — загрузка занятий, студентов, посещаемости и оценок через существующие API.

На этапе GUI-010 журнал только отображает существующие данные. Редактирование оценок и посещаемости остается будущим этапом. Backend, БД и API не менялись.

Связи между разделами нового GUI:

- из карточки студента можно открыть связанную группу через `/groups?selected=<group_id>`;
- `/groups` читает query-параметр `selected` и выбирает соответствующую группу;
- из карточки группы можно открыть список студентов группы через `/students?group=<group_id>`;
- `/students` читает query-параметр `group`, применяет фильтр по группе и показывает активный фильтр чипом.
- глобальный поиск в верхней панели использует `frontend/src/services/searchService.js`, `frontend/src/stores/search.js` и компонент `frontend/src/components/search/GlobalSearch.vue`;
- глобальный поиск работает по студентам, группам, преподавателям, дисциплинам и аудиториям, хранит историю запросов в `localStorage` и открывает карточки через query-параметры `selected` и `search`.

Интерфейс ориентирован на рабочий стиль, близкий к 1С-Колледж:

- плотные таблицы;
- фильтры над таблицами;
- компактные статусы;
- рабочие карточки и сводки;
- минимум декоративных элементов;
- акцент на повторяемые операции учебной части и приемной комиссии.

## Структура базы данных

Ниже перечислены ключевые таблицы MVP. Полная версия поддерживается в `DATABASE_SCHEMA_MVP.md`.

### Пользователи и права

`users`:

- `id`;
- `name`;
- `email`;
- `password`;
- `role_id`;
- `is_active`;
- `api_token_hash`;
- timestamps.

`roles`:

- `id`;
- `name`;
- `code`;
- `description`;
- timestamps.

`permissions`:

- `id`;
- `name`;
- `code`;
- `description`;
- timestamps.

Связи ролей и прав реализованы через промежуточную таблицу.

### Учебные справочники

`groups`:

- `id`;
- `name`;
- `specialty`;
- `education_program_id`;
- `course`;
- `year_start`;
- `curator_id`;
- timestamps.

`students`:

- `id`;
- `user_id`;
- `group_id`;
- `last_name`;
- `first_name`;
- `middle_name`;
- `birth_date`;
- `phone`;
- `email`;
- `status`;
- `enrollment_date`;
- timestamps.

Статусы студента:

- `active` — обучается;
- `academic_leave` — академический отпуск;
- `graduated` — выпущен;
- `expelled` — отчислен.

`teachers`:

- ФИО;
- телефон;
- email;
- должность;
- отделение;
- признак активности.

`subjects`:

- название;
- код;
- отделение;
- описание;
- связь с преподавателями.

`classrooms`:

- номер;
- корпус;
- этаж;
- вместимость;
- тип;
- описание.

### Специальности и программы

`specialties`:

- `code`;
- `name`;
- `education_level`;
- `qualification`;
- `normative_study_years`;
- `description`.

`education_programs`:

- `specialty_id`;
- `name`;
- `year_start`;
- `study_form`;
- `study_years`;
- `is_active`;
- `description`.

Группы могут быть связаны с образовательными программами.

### Расписание, журнал, отчеты

`schedule_lessons`:

- группа;
- преподаватель;
- дисциплина;
- аудитория;
- дата;
- время начала;
- время окончания;
- тип занятия;
- тема.

Проверки конфликтов:

- преподаватель не может вести два занятия одновременно;
- группа не может иметь два занятия одновременно;
- аудитория не может быть занята двумя занятиями одновременно.

`attendance`:

- занятие;
- студент;
- статус;
- комментарий.

Статусы посещаемости:

- `present`;
- `absent`;
- `late`;
- `excused`.

`grades`:

- занятие;
- студент;
- оценка;
- тип оценки;
- комментарий.

### Приемная комиссия

`applicant_applications`:

- образовательная программа;
- ФИО;
- дата рождения;
- телефон;
- email;
- база поступления;
- статус;
- дата подачи;
- комментарий.

Статусы заявления:

- `new` — новое;
- `accepted` — принято;
- `needs_clarification` — требуется уточнение;
- `rejected` — отклонено;
- `enrolled` — зачислен.

База поступления:

- `after_9`;
- `after_11`.

`applicant_application_documents`:

- заявление;
- тип;
- название;
- признак получения;
- дата получения;
- номер;
- комментарий.

Обязательные документы MVP:

- паспорт;
- документ об образовании;
- СНИЛС;
- согласие на обработку персональных данных;
- фотография;
- медицинская справка.

`applicant_application_events`:

- заявление;
- тип события;
- заголовок;
- описание;
- meta JSON;
- timestamps.

## Реализованные модули

### Авторизация и RBAC

- вход по email/паролю;
- выдача API token;
- профиль текущего пользователя;
- logout;
- middleware `api.token`;
- middleware `permission`;
- роли и permissions;
- разграничение доступа по разделам.

### Справочники

Реализованы CRUD, API Resources, валидация, frontend-страницы и CSV-обмен для:

- студентов;
- групп;
- преподавателей;
- дисциплин;
- аудиторий;
- специальностей;
- образовательных программ.

### Студенты

Реализовано:

- список студентов;
- фильтры по ФИО, группе и статусу;
- создание, редактирование, удаление;
- импорт и экспорт CSV;
- карточка студента;
- отображение группы, программы, контактов, статуса, даты рождения и даты зачисления;
- сводка посещаемости;
- сводка оценок;
- последние посещения и оценки.

### Расписание

Реализовано:

- создание занятия;
- выбор группы, преподавателя, дисциплины, аудитории;
- дата, время, тип занятия, тема;
- фильтры;
- редактирование и удаление;
- проверки конфликтов группы, преподавателя и аудитории.

### Электронный журнал

Реализовано:

- выбор занятия;
- список студентов группы;
- отметка посещаемости;
- выставление оценки за работу на занятии;
- история оценок.

### Отчеты

Реализовано:

- отчет посещаемости по группе;
- CSV-экспорт отчета посещаемости;
- отчет оценок по группе и дисциплине;
- CSV-экспорт отчета оценок.

### Публичный раздел «Абитуриенту»

Реализовано:

- просмотр активных образовательных программ без авторизации;
- карточки программ;
- поиск;
- фильтр по форме обучения: все, очная, заочная;
- отображение специальности, квалификации и срока обучения.

### Приемная комиссия

Реализовано:

- реестр заявлений абитуриентов;
- создание, редактирование и удаление заявления;
- импорт и экспорт CSV;
- подробные ошибки импорта по строкам;
- фильтры по поиску, программе, статусу, базе поступления и комплектности документов;
- активные фильтры с быстрым сбросом;
- сводки по статусам, программам и форме обучения;
- новый Quasar GUI по маршруту `/admissions` с таблицей, правой карточкой заявления, быстрыми статусами и глобальным поиском;
- быстрые очереди: новые, готовы к зачислению, неполный комплект, уже зачислены, отклонены;
- обязательные документы;
- дата, номер и комментарий по документу;
- история событий заявления;
- зачисление абитуриента в студенты;
- запрет зачисления без полного комплекта обязательных документов.

## Текущие задачи

Актуальный список поддерживается в `TASKS.md`.

Ближайшие задачи после ADM-001 и ADM-001.1:

1. GUI-015: Учебные планы.
2. QR-001: Проходная / QR-пропуска Phase 1.
3. MOB-001: Mobile Student Cabinet Phase 1.
4. GRAD-001: Выпускники и дипломы.
5. FRDO-001: Подготовка данных ФРДО.
6. FIS-001: ФИС ГИА / ФИС Приема.
7. GUI-016: Нагрузка преподавателей.
8. GUI-017: Экзамены / ГИА.

Текущий приоритет: развивать CollegePortal как связанную платформу, где учебные планы питают расписание, журнал, нагрузку преподавателей, выпуск, ФРДО и интеграции с государственными системами.

## Roadmap

Кратко: актуальный полный план находится в `ROADMAP.md`.

### Выполнено после ADM-001

- ADM-001: новый Quasar-раздел «Приемная комиссия»;
- ADM-001.1: проверка и полировка приемной комиссии;
- заявления абитуриентов добавлены в глобальный поиск;
- карточка заявления справа показывает сведения, документы, историю и действия;
- через существующий API доступны CRUD, импорт/экспорт CSV, отметки документов и зачисление.

### Ближайший порядок

1. GUI-015: Учебные планы.
2. QR-001: Проходная / QR-пропуска Phase 1.
3. MOB-001: Mobile Student Cabinet Phase 1.
4. GRAD-001: Выпускники и дипломы.
5. FRDO-001: Подготовка данных ФРДО.
6. FIS-001: ФИС ГИА / ФИС Приема.
7. GUI-016: Нагрузка преподавателей.
8. GUI-017: Экзамены / ГИА.

### Ключевые будущие контуры

- QR-001: печатный QR, мобильный QR, сканирование на проходной, журнал входа/выхода, отчеты по студентам, преподавателям, сотрудникам и гостям; основа — Identity Domain.
- MOB-001: расписание, оценки, посещаемость, уведомления, мобильный QR-пропуск.
- GRAD-001: выпускники, шаблоны дипломов, печать дипломов, приложения к диплому, история выдачи.
- FRDO-001: подготовка данных, проверка полноты, выгрузка, журнал отправки, ошибки и статусы.
- FIS-001: приемные кампании, данные абитуриентов, экспорт/импорт, журнал обмена, ошибки и статусы.

## Инструкции по запуску

### Требования

Для Ubuntu Server:

- Docker Engine;
- Docker Compose plugin;
- доступ в интернет.

Для Windows:

- Docker Desktop;
- PowerShell;
- доступ в интернет.

### Первый запуск

Создать env-файл:

```bash
cp .env.example .env
```

На Windows:

```powershell
Copy-Item .env.example .env
```

Инициализировать проект, если Laravel/Vue еще не установлены:

```bash
chmod +x scripts/*.sh
./scripts/init-project.sh
```

На Windows:

```powershell
.\scripts\init-project.ps1
```

Запустить окружение:

```bash
docker compose up -d
```

Проверить контейнеры:

```bash
docker compose ps
```

### Адреса локального окружения

- Backend через Nginx: `http://localhost:8080`;
- API: `http://localhost:8080/api`;
- Frontend Vite: `http://localhost:5173`;
- PostgreSQL: `localhost:5432`.

На текущей Ubuntu VM:

- Backend через Nginx: `http://192.168.34.104:8080`;
- Frontend Vite: `http://192.168.34.104:5173`.

### Частые команды разработки

Backend-тесты:

```bash
docker compose exec -T backend php artisan test
```

Frontend-сборка:

```bash
docker compose exec -T frontend npm run build
```

Перезапуск backend:

```bash
docker compose restart backend
```

Перезапуск frontend:

```bash
docker compose restart frontend
```

Миграции:

```bash
docker compose exec -T backend php artisan migrate
```

Миграции с seeders в тестовой/локальной среде:

```bash
docker compose exec -T backend php artisan migrate:fresh --seed
```

## Правила сопровождения документации

Документы проекта нужно обновлять после:

- добавления нового модуля;
- изменения структуры БД;
- изменения API-контрактов;
- заметного изменения интерфейса;
- изменения roadmap или ближайших задач.

Основные документы:

- `PROJECT_CONTEXT.md` — общий контекст для нового разработчика;
- `TASKS.md` — текущие задачи;
- `ROADMAP.md` — этапы;
- `MVP_SPEC.md` — функциональная спецификация;
- `DATABASE_SCHEMA_MVP.md` — схема БД;
- `EXTERNAL_SERVICES_NOTES.md` — интеграции и внешние форматы;
- `SECURITY_NOTES.md` — безопасность.

## Важные замечания

- Проект предназначен для российского СПО/колледжа искусств.
- Для интерфейса ориентиром является рабочий стиль 1С-Колледж: компактно, таблично, без лишней декоративности.
- В тестовой среде используются фиктивные персональные данные.
- Реальные персональные данные нельзя загружать в тестовую среду.
- Перед реальным внедрением нужно отдельно проработать требования по персональным данным, HTTPS, резервному копированию, журналированию действий и доступам.
