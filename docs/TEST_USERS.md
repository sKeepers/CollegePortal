# Тестовые пользователи DEV

## Назначение

Эти учетные записи предназначены только для проверки DEV-стенда CollegePortal.

DEV URL:

```text
http://192.168.34.114:5174/login
```

## Пользователи

| Роль | Логин |
| --- | --- |
| Администратор | `admin@college-portal.local` |
| Приемная комиссия | `admission@college-portal.local` |
| Преподаватель | `teacher@college-portal.local` |
| Студент | `student@college-portal.local` |

Пароль для DEV smoke:

```text
test1234
```

## Ограничения

- Использовать только на DEV.
- Не использовать в PROD.
- Перед production deployment пароль должен быть заменен или учетные записи должны быть отключены.
- Реальные учетные записи студентов, сотрудников и администрации не должны использоваться для smoke-тестов.

## UAT-002 smoke routes

| Роль | Проверяемые страницы |
| --- | --- |
| Администратор | `/dashboard`, `/admissions/foundation`, `/identity/digital-passes`, `/access/gate`, `/access/mobile-scanner`, `/access/reports` |
| Приемная комиссия | `/dashboard`, `/admissions/foundation` |
| Преподаватель | `/dashboard`, `/identity/my-pass` |
| Студент | `/dashboard`, `/identity/my-pass`, `/m/student/pass` |

Обычные `teacher` и `student` не должны видеть административные страницы `/identity/digital-passes`, `/access/gate`, `/access/mobile-scanner` и `/access/reports`.
