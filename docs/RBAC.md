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
- Action-level кнопки и панели основных CRUD-разделов переведены на frontend permission checks в RBAC-001.1; API-403 остается источником истины и обязательной защитой.

## RBAC-001.1: permission-aware CRUD UI

Frontend получил единый слой проверки permissions:

- `frontend/src/composables/usePermissions.js` — методы `hasPermission`, `hasAnyPermission`, `hasAllPermissions`;
- `frontend/src/components/PermissionGuard.vue` — универсальная обертка для условного отображения элементов;
- `/forbidden` — страница 403 для запрещенных маршрутов;
- router guard поддерживает `meta.permission`, `meta.permissionsAny` / `meta.permissions`, `meta.permissionsAll`.

Правила UI:

- кнопки создания, редактирования, удаления, импорта, экспорта и специальных действий скрываются без нужного permission;
- обработчики действий дополнительно делают ранний `return`, чтобы форма не открывалась через query-параметр или программный вызов;
- прямой переход на запрещенный route отправляет пользователя на `/forbidden`;
- frontend-скрытие не заменяет backend-проверку, все API остаются защищены middleware `permission:`.

Покрытые разделы:

- контингент: студенты, группы, преподаватели;
- справочники учебного процесса: дисциплины, аудитории;
- приемная комиссия;
- учебные планы, нагрузка, экзамены;
- выпускники и дипломы;
- ФРДО и ФИС;
- цифровые пропуска;
- системные разделы: пользователи, роли, permissions, настройки, справочники, импорт, управление демо-данными.

## PERSON-001 permissions

Добавлены permissions `people.view`, `people.create`, `people.update`, `people.link`, `people.merge`. На текущем этапе frontend `/people` и read-only API используют `people.view`; `people.link` используется как административное право для безопасного связывания профилей через command. `people.merge` зарезервирован для будущего этапа и не дает UI-действия.
