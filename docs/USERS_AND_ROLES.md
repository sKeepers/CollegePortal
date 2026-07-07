# USERS_AND_ROLES: пользователи и роли CollegePortal

Дата: 07.07.2026.
Окружение реализации: DEV `/srv/college-dev`.

## Назначение

Раздел `/admin/users` предназначен для первого этапа управления учетными записями CollegePortal.

На этапе CORE-001A модуль решает базовые задачи:

- просмотр пользователей системы;
- поиск по имени и email;
- фильтр по статусу;
- создание и редактирование учетной записи;
- блокировка и разблокировка входа;
- удаление учетной записи;
- указание роли;
- подготовительная связь с центральной сущностью Person через `person_type` и `person_id`.

## Текущий этап

Это MVP управления пользователями. Существующая авторизация сохранена:

- вход выполняется по email/password;
- активность пользователя проверяется через `is_active`;
- API-токен хранится в виде hash;
- `last_login_at` обновляется при входе.

Дополнительно добавлены поля:

- `person_type`;
- `person_id`.

Они нужны для будущего Identity Domain, где один Person сможет иметь разные роли: студент, преподаватель, сотрудник, абитуриент, гость, выпускник.



## CORE-001B: управление ролями

Добавлен MVP раздела `/admin/roles`.

Возможности:

- список ролей;
- поиск по названию, коду и описанию;
- создание роли;
- редактирование роли;
- удаление роли, если она не назначена пользователям;
- просмотр количества пользователей в роли;
- назначение одной или нескольких ролей пользователю;
- выбор основной роли пользователя.

Текущая авторизация остается совместимой с `users.role_id`: это основная роль пользователя. Для будущего расширения добавлена таблица `role_user`, где можно хранить несколько ролей и флаг `is_primary`.

## Роли

Роли уже существуют в проекте и используются как простая основа RBAC:

- `admin` — полный доступ;
- `director` — директор, просмотр отчетов;
- `deputy` — заместитель директора, контроль учебного процесса и отчетов;
- `study` — учебная часть, справочники, расписание и журнал;
- `admission` — приемная комиссия, абитуриенты и отчеты;
- `teacher` — журнал и личные данные;
- `student` — личные данные;
- `security` — проходная и отчеты по проходам.

Legacy-роли `academic_office` и `curator` сохраняются для совместимости старых данных.

На этапе CORE-001A роли в интерфейсе считаются подготовительной основой. Сложные сценарии RBAC пока не внедряются.

## Demo-пользователи UAT

Seeder `UatUserSeeder` создает demo-пользователей только вне production.

Пароль для всех demo-пользователей: `demo12345`.

| Назначение | Email | Роль |
|---|---|---|
| admin | `admin.uat@college-portal.local` | admin |
| director | `director.uat@college-portal.local` | director |
| deputy | `deputy.uat@college-portal.local` | deputy |
| study | `study.uat@college-portal.local` | study |
| admission | `admission.uat@college-portal.local` | admission |
| teacher1 | `teacher1.uat@college-portal.local` | teacher |
| student1 | `student1.uat@college-portal.local` | student |
| security | `security.uat@college-portal.local` | security |

## Production-защита

`UatUserSeeder` проверяет окружение приложения. В `production` demo-пользователи не создаются и существующие учетные записи не изменяются.

## API

Доступ к API требует `api.token` и permission `manage_users`.

- `GET /api/admin/roles`;
- `POST /api/admin/roles`;
- `PUT /api/admin/roles/{id}`;
- `DELETE /api/admin/roles/{id}`;
- `GET /api/admin/users`;
- `POST /api/admin/users`;
- `GET /api/admin/users/{id}`;
- `PUT /api/admin/users/{id}`;
- `DELETE /api/admin/users/{id}`;
- `POST /api/admin/users/{id}/block`;
- `POST /api/admin/users/{id}/unblock`;
- `GET /api/admin/users/roles`;
- `GET /api/admin/users/people`;
- `POST /api/admin/users/{id}/roles`.

## Дальнейший план RBAC

1. Разделить системные роли и доменные роли Person.
2. Добавить матрицу прав в интерфейс администратора.
3. Добавить аудит изменений учетных записей.
4. Добавить приглашения пользователей и принудительную смену пароля.
5. Подключить LDAP/Active Directory.
6. Связать пользователей с Digital Identity, Mobile Pass и журналом входов.
