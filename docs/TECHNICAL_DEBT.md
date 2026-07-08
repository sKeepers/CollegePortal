# EPIC-001: Technical Debt Review

Дата: 08.07.2026.  
Окружение: DEV `/srv/college-dev`.

## Краткий вывод

Технический долг CollegePortal в основном связан не с ошибочной архитектурой, а с быстрым ростом MVP. Система уже работает, тесты проходят, но некоторые классы и страницы стали слишком крупными. Главная задача следующего этапа — разрезать большие участки на устойчивые модули без изменения поведения.

## Высокий приоритет

### 1. `UniversalImportService` слишком большой

Файл: `backend/app/Services/UniversalImportService.php`  
Размер: около 60 KB.

Сейчас в одном классе находятся:

- конфигурация типов данных;
- CSV/XLSX parsing;
- шаблоны CSV;
- mapping;
- validation;
- resolve связей;
- импорт простых сущностей;
- импорт составных сущностей;
- проверка конфликтов расписания.

Риск: каждое новое направление импорта будет усложнять класс и повышать вероятность регрессий.

Рекомендация: выделить `ImportTarget`/`ImportHandler` классы:

- `StudentImportTarget`;
- `GroupImportTarget`;
- `CurriculumImportTarget`;
- `TeachingLoadImportTarget`;
- `ScheduleImportTarget`;
- общий `CsvXlsxParser`;
- общий `ImportTemplateService`.

### 2. Legacy `App.vue` остается очень крупным

Файл: `frontend/src/App.vue`  
Размер: около 133 KB.

Риск: даже при сохранении `/legacy`, файл усложняет поддержку и увеличивает bundle.

Рекомендация: оставить legacy доступным, но запретить добавление нового функционала в legacy. Позже вынести legacy в lazy-loaded route и/или архивный модуль.

### 3. Крупные frontend CRUD-страницы

Крупные файлы:

- `GraduationPage.vue`;
- `ExamsPage.vue`;
- `UsersPage.vue`;
- `UniversalImportPage.vue`;
- `CurriculaPage.vue`;
- `AdmissionsPage.vue`;
- `TeachingLoadPage.vue`.

Риск: повторение логики фильтров, таблиц, карточек, форм, import/export.

Рекомендация: выделить composables:

- `useCrudPage`;
- `useTablePagination`;
- `useImportExport`;
- `useRightDetailsPanel`;
- `useReferenceOptions`.

## Средний приоритет

### 4. Два подхода к импорту

Сейчас есть универсальный импорт и отдельные CSV-сервисы модулей. Это исторически понятно, но создает дублирование:

- validation и line errors;
- CSV parsing;
- mapping;
- import summary;
- duplicate handling.

Рекомендация: новые импорты делать только через `/admin/import`. Старые endpoint imports оставить для совместимости, но постепенно перевести их на общие handlers.

### 5. Контроллеры с большим количеством обязанностей

Некоторые контроллеры совмещают CRUD, import/export, вложенные строки и специальные действия. Наиболее заметны:

- `ExamController`;
- `GraduateController`;
- `CurriculumController`;
- `TeachingLoadController`;
- `ApplicantApplicationController`.

Рекомендация: выносить бизнес-операции в services/actions, контроллер оставить тонким.

### 6. RBAC недостаточно детализирован

Есть роли, permissions и middleware, но многие операции живут под широким `manage_dictionaries`.

Рекомендация: перейти к permissions по модулям:

- `manage_students`;
- `manage_groups`;
- `manage_admissions`;
- `manage_identity`;
- `manage_access`;
- `manage_imports`;
- `view_audit`;
- `manage_settings`.

### 7. Reference Data интегрирован не везде

Справочники уже используются в части модулей, но часть статусов и типов еще может оставаться строками в коде или импорте.

Рекомендация: провести CORE-004C: full reference data integration.

## Низкий приоритет

### 8. Mock-данные в Dashboard и Mobile

Mock-данные явно помечены, что хорошо. Но перед UAT пользователи могут воспринимать их как реальные.

Рекомендация: визуально помечать виджеты как демонстрационные или заменить API-заглушками.

### 9. SearchService будет расти

Глобальный поиск уже работает как foundation. По мере добавления сущностей может превратиться в большой frontend service.

Рекомендация: перейти к registry providers: `searchProviders/studentProvider`, `groupProvider`, etc.

### 10. Документация растет быстрее оглавления

Документов много, что хорошо для проекта, но нужен периодический индекс и устаревание документов.

Рекомендация: поддерживать `docs/ARCHITECTURE_DOCUMENTATION.md` как единую карту.

## Что не считать долгом

- Наличие legacy `/legacy`: это осознанная стратегия безопасной миграции.
- MVP-заглушки в FRDO/FIS: реальная интеграция сознательно отложена.
- Отдельные таблицы `student`, `teacher`, `applicant`: Person domain еще не внедрен и не должен ломать текущий MVP.

## Правило работы с долгом

Каждый refactor должен:

- не менять API;
- не менять БД без отдельной задачи;
- иметь тесты до/после;
- завершаться git checkpoint;
- быть маленьким и обратимым.
