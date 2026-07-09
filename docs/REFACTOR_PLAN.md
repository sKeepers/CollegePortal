# EPIC-001: Refactor Plan без изменения функциональности

Дата: 08.07.2026.  
Окружение: DEV `/srv/college-dev`.

## Принципы

- Не менять API без отдельного ADR/задачи.
- Не менять БД в refactor-only задачах.
- Не менять бизнес-логику.
- Каждый шаг должен иметь тесты до/после.
- Сначала покрытие и characterization tests, затем рефакторинг.
- Маленькие git checkpoints после каждой задачи.

## Phase 1. Stabilization после Release 0.7

### REF-001: Characterization tests для универсального импорта

Цель: закрепить текущее поведение перед разбором `UniversalImportService`.

Покрыть:

- все 9 типов данных;
- create/update/skip duplicates;
- ошибки mapping;
- ошибки связанных сущностей;
- конфликты расписания;
- template download.

### REF-002: Разделить UniversalImportService

Без изменения API выделить:

- `ImportFileParser`;
- `ImportTemplateService`;
- `ImportTargetRegistry`;
- target handlers для каждого типа;
- common row error builder.

Критерий готовности: все тесты импорта проходят, `/admin/import` работает без изменений для пользователя.

### REF-003: Унифицировать старые CSV imports

Цель: новые возможности делать через universal import, старые endpoint imports оставить совместимыми.

План:

- описать legacy CSV services;
- перевести повторяющуюся parsing/error логику на общий компонент;
- не удалять старые endpoints до отдельного решения.

## Phase 2. Frontend maintainability

### REF-010: Lazy loading router

Перевести routes на dynamic imports:

- legacy;
- admin modules;
- FRDO/FIS;
- Graduation;
- Exams;
- Import.

Критерий: `npm run build` успешен, chunks меньше, страницы открываются.

### REF-011: CRUD composables

Выделить повторяемые паттерны:

- фильтры;
- pagination settings;
- right details panel;
- create/edit/delete dialog;
- import/export actions.

Начать с Students/Groups/Teachers как наиболее понятных CRUD.

### REF-012: Legacy freeze

Правило: не добавлять новый функционал в `frontend/src/App.vue`. Все новые модули только в новой Quasar-структуре.

## Phase 3. RBAC and security hardening

### REF-020: Permission matrix

Создать документ и seed permissions:

- `manage_students`;
- `manage_groups`;
- `manage_teachers`;
- `manage_schedule`;
- `manage_journal`;
- `manage_admissions`;
- `manage_imports`;
- `manage_access`;
- `manage_identity`;
- `view_audit`;
- `manage_settings`.

### REF-021: Route permission split

Разделить широкую группу `manage_dictionaries` на точные permissions без изменения UI.

### REF-022: Request classes for admin endpoints

Вынести inline validation из users/roles/reference/settings в Form Request classes.

## Phase 4. Person and Identity preparation

### REF-030: Person compatibility layer

Не мигрировать данные сразу. Сначала создать read-model/service, который умеет возвращать owner для student/teacher/applicant/graduate.

Использовать в:

- QR;
- AccessEventResource;
- Mobile Student;
- PersonPhoto;
- global search.

### REF-031: Unified owner resolver

Заменить scattered entity_type/entity_id lookups на общий `OwnerResolver`.

## Phase 5. Performance and operations

### REF-040: Import queue design

Спроектировать queued import для больших файлов:

- upload;
- preview;
- validate job;
- confirm job;
- progress;
- cancellation;
- retention.

### REF-041: Audit/import retention

Добавить регламент и затем реализацию очистки/архивации старых import files, import jobs и audit logs.

### REF-042: Access reports profiling

На реальных данных проверить `/access/reports` и индексы.

## Не делать сейчас

- Не переписывать проект с нуля.
- Не удалять legacy до завершения миграции.
- Не внедрять Person с большой миграцией без отдельного ADR.
- Не менять API ради красоты.
- Не оптимизировать преждевременно до пилотных данных, кроме очевидного lazy loading.

## Рекомендуемый ближайший порядок

1. REF-001: characterization tests для импорта.
2. REF-002: разбор UniversalImportService.
3. REF-010: router lazy loading.
4. REF-020: permission matrix.
5. REF-030: owner/person resolver foundation.

## Выполнено: REFACTOR-001

`UniversalImportService` разделен на фасад/координатор и набор `ImportHandler` классов. Старый public workflow `/admin/import` сохранен: preview, mapping, validation, confirm и `ImportJob` работают через тот же API.

Следующие безопасные шаги:

- вынести CSV/XLSX parser в отдельный `ImportFileParser`;
- вынести template generation в `ImportTemplateService`;
- добавить отдельные tests для каждого handler;
- постепенно подключить старые module CSV endpoints к тем же handlers.

## Выполнено: REFACTOR-002

Router переведен на lazy loading для frontend page-компонентов. Синхронными оставлены layout-компоненты, чтобы не менять базовую структуру приложения.

Проверено:

- `npm run build`;
- `/dashboard`;
- `/students`;
- `/admissions`;
- `/admin/import`;
- `/admin/settings`;
- `/access/gate`;
- `/m/student`;
- `/legacy`.

Основной JS chunk уменьшился примерно с `824 KB` до `179 KB`; предупреждение Vite о chunk больше 500 KB исчезло.
