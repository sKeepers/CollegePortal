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

## BULK-001: permissions for bulk operations

Добавлены точечные permissions для массовых операций Admissions и Students. UI скрывает недоступные действия, но источником истины остается backend: каждый bulk endpoint проверяет permission по выбранному action. Director получает только bulk export, admission - операции приемной комиссии, study/deputy - операции студентов, admin - полный доступ.


## ADM-DOCS-001: Applicant document permissions

Добавлены permissions для registry документов заявления: `admissions.documents.view`, `admissions.documents.receive`, `admissions.documents.upload`, `admissions.documents.verify`, `admissions.documents.reject`, `admissions.documents.delete`, `admissions.documents.download`.

Матрица: `admin` имеет полный доступ; `admission` может смотреть, принимать, загружать, проверять, отклонять и скачивать; `director` может смотреть и скачивать; `deputy` и `study` могут смотреть; `teacher`, `student`, `security` доступа не имеют.

## ST-001B: Teaching Load Engine permissions

Добавлены permissions `teaching_load.generate`, `teaching_load.assign`, `teaching_load.bulk_assign`, `teaching_load.view_coverage`. Учебная часть и заместитель директора могут формировать нагрузку и назначать преподавателей; директор видит coverage; преподаватель сохраняет просмотр своей нагрузки без генерации.

## Schedule Engine permissions (ST-002A)

Добавлены точечные permissions:

- `schedule.create`;
- `schedule.delete`;
- `schedule.validate`;
- `schedule.manage_templates`;
- `schedule.manage_replacements`;
- `schedule.view_conflicts`;
- `schedule.view_coverage`.

`admin` получает все права. `director` получает просмотр, конфликты и покрытие. `deputy`, `study`, `academic_office` получают управление расписанием. `teacher` и `student` сохраняют только просмотр своего расписания на уровне UI/API-фильтрации будущих этапов.

## Visual Schedule Editor (ST-002B)

Редактор недели показывает действия только при наличии точечных permissions. Director видит расписание без edit-действий. Учебная часть и заместитель директора могут создавать, переносить и применять шаблоны. Teacher/student остаются в read-only сценариях.

## ST-003A Journal Integration

Schedule Engine entries can now be opened as electronic journal lessons. Journal Engine stores topic, homework, attendance, grades, private files and signature status while preserving the schedule entry as the source of group, subject, teacher, date and time. Access is controlled by `journal.*` permissions and all mutating journal actions are audited.

## ST-003B Teacher Journal Workspace permissions

Teacher workspace uses existing `journal.*` permissions with stricter UI behavior. Teachers can see and edit only their own lessons. `journal.view_all` enables study/deputy/admin control mode. Signed lessons are read-only unless the user has `journal.reopen`; reopening requires a reason and does not bypass Audit.

## HR permissions

Добавлена роль `hr` и permissions: `hr.employees.view`, `hr.employees.create`, `hr.employees.update`, `hr.employees.dismiss`, `hr.assignments.manage`, `hr.statuses.manage`, `hr.departments.manage`, `hr.positions.manage`, `hr.documents.view`, `hr.reports.view`.

Матрица: `admin` получает все права; `hr` управляет кадровым контуром; `director` получает просмотр сотрудников и HR-отчеты; `deputy/study/academic_office` получают просмотр сотрудников, управление статусами и HR-отчеты; `teacher/student/security/admission` не получают общий доступ к HR-списку.

## HR-001B permissions

Добавлены permissions: `hr.calendar.view`, `hr.calendar.manage`, `hr.absences.manage`, `hr.dismissals.manage`, `hr.replacements.view`, `hr.replacements.manage`, `hr.reports.view`.

Роли: `hr` управляет календарем и заменами; `deputy/study/academic_office` видят отсутствия и управляют заменами; `director` видит календарь и отчеты; `teacher` получает read-only доступ только к собственным периодам; `student/security/admission` доступа не имеют.


## FIS Outbound Permissions

Added permissions: `fis.outbound.view`, `fis.outbound.create`, `fis.outbound.generate`, `fis.outbound.validate`, `fis.outbound.send_test`, `fis.outbound.send_production`, `fis.outbound.status`, `fis.outbound.download`, `fis.settings.manage`. Production send is feature-flagged and not active in FIS-API-001.

## DOCS-ENGINE-001

- Добавляется Document Engine для печатных форм, справки об обучении, журнала документов и публичной проверки.
- Права: `documents.view`, `documents.create`, `documents.generate`, `documents.issue`, `documents.cancel`, `documents.reprint`, `documents.download_docx`, `documents.download_pdf`, `documents.export`, `documents.templates.view`, `documents.templates.manage`, `documents.templates.publish`, `documents.types.manage`, `documents.verify_private`, `documents.view_sensitive_data`.
- Admin получает все права; study/deputy получают создание, генерацию, выдачу и скачивание; director получает просмотр и скачивание.
