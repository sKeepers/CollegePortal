# RBAC: матрица разрешений CollegePortal

## Назначение

RBAC-001 переводит CollegePortal с укрупненных прав (`manage_dictionaries`, `manage_users`, `manage_schedule`, `manage_journal`, `view_reports`) на полноценную матрицу разрешений. Роли сохранены, пользователи не теряются, старые permissions остаются как legacy-совместимость.

Права проверяются на двух уровнях:

- backend API через middleware `permission:` и `User::hasPermission()`;
- frontend меню, маршруты и быстрые действия через `auth.can(permission)`.

## Модель данных

Таблицы:

- `permissions`;
- `permission_role`;
- `roles`;
- `role_user`.

`permissions` содержит:

- `code` — машинный код, например `students.view`;
- `name` — русское название;
- `module` — модуль платформы;
- `description` — назначение;
- `system` — системное разрешение;
- `active` — активно ли разрешение.

## Основные permissions

### Students

- `students.view`
- `students.create`
- `students.update`
- `students.delete`

### Teachers

- `teachers.view`
- `teachers.create`
- `teachers.update`
- `teachers.delete`

### Groups

- `groups.view`
- `groups.create`
- `groups.update`
- `groups.delete`

### Academic

- `schedule.view`
- `schedule.update`
- `journal.view`
- `journal.edit`
- `journal.export`
- `curricula.view`
- `curricula.edit`
- `teachingload.view`
- `teachingload.edit`
- `exams.view`
- `exams.edit`

### Admissions and Graduation

- `admissions.view`
- `admissions.edit`
- `graduation.view`
- `graduation.edit`

### Integrations

- `frdo.view`
- `frdo.export`
- `fis.view`
- `fis.export`

### Identity

- `gate.scan`
- `gate.reports`
- `digitalpasses.manage`

### System

- `users.manage`
- `roles.manage`
- `permissions.manage`
- `settings.manage`
- `audit.view`
- `reference.manage`
- `import.manage`

## Матрица ролей

| Роль | Доступ |
|---|---|
| `admin` | Все permissions |
| `director` | Просмотр основных модулей, Dashboard, отчеты, аудит, без изменения данных и системного управления |
| `deputy` | Учебный процесс, контингент, расписание, журнал, отчеты, выпуск, импорт |
| `study` | Учебная часть: аналогично `deputy` для операционной работы |
| `academic_office` | Legacy-роль учебной части, синхронизирована с учебным процессом |
| `admission` | Приемная комиссия, просмотр студентов/групп, импорт и справочники |
| `teacher` | Свое расписание, журнал, нагрузка, экзамены, QR-пропуск; ownership будет усилен отдельным этапом |
| `student` | Личный кабинет, свое расписание, журнал/оценки, QR; ownership будет усилен отдельным этапом |
| `security` | Проходная, мобильный сканер, отчеты проходной, цифровые пропуска |
| `curator` | Teacher permissions + просмотр студентов/групп и attendance reports |

## Backend

Middleware `permission:` принимает старый или новый код. Для обратной совместимости старые route-группы остаются в `api.php`, но `EnsurePermission` мапит их на точечные permissions по URI и HTTP-методу.

Примеры:

- `GET /api/students` -> `students.view`;
- `POST /api/students` -> `students.create`;
- `PATCH /api/students/{id}` -> `students.update`;
- `DELETE /api/students/{id}` -> `students.delete`;
- `GET /api/frdo-packages` -> `frdo.view`;
- `POST /api/frdo-packages/{id}/mark-exported` -> `frdo.export`;
- `POST /api/access/scan` -> `gate.scan`;
- `GET /api/access/reports/events` -> `gate.reports`.

## Frontend

`AuthStore` получает объединенный список permissions всех ролей пользователя. Меню, маршруты и быстрые действия Dashboard используют `auth.can(permission)`.

Страница управления:

- `/admin/permissions`.

Возможности:

- просмотр permissions;
- поиск;
- фильтр по модулю;
- просмотр ролей, которым назначено permission;
- назначение permission ролям.

## Ограничения текущего этапа

- Ownership для teacher/student пока подготовлен архитектурно, но не доведен до фильтрации “только свои” на каждом API.
- Старые permissions сохранены для совместимости тестов и legacy-поведения.
- Кнопки внутри отдельных CRUD-страниц частично продолжают полагаться на API-403; полная унификация action-level кнопок запланирована следующим UX/RBAC этапом.
