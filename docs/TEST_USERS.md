# Тестовые пользователи DEV

## Назначение

Эти учетные записи предназначены только для проверки DEV-стенда CollegePortal.

DEV URL:

```text
https://192.168.34.114:5443/login
```

Порт `5174` — только локальный диагностический порт Vite на самом DEV-сервере, снаружи он не опубликован и по HTTPS не отвечает.

Мобильный сканер требует доверенного secure context, поэтому открывать его нужно тоже по HTTPS:

```text
https://192.168.34.114:5443/access/mobile-scanner
```

## Обычные smoke-пользователи

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

## UAT-пользователи по ролям

Эти учетные записи предназначены для сквозной проверки сценариев под разными ролями.
Они отличаются от обычных smoke-пользователей выше.

| Роль | Логин |
| --- | --- |
| Администратор UAT | `admin.uat@college-portal.local` |
| Директор UAT | `director.uat@college-portal.local` |
| Заместитель директора UAT | `deputy.uat@college-portal.local` |
| Учебная часть UAT | `study.uat@college-portal.local` |
| Приемная комиссия UAT | `admission.uat@college-portal.local` |
| Преподаватель UAT | `teacher1.uat@college-portal.local` |
| Студент UAT | `student1.uat@college-portal.local` |
| Сотрудник проходной UAT | `security.uat@college-portal.local` |

Пароль для UAT-пользователей DEV:

```text
demo12345
```

Например, для входа как директор UAT используйте логин
`director.uat@college-portal.local` и пароль из этого раздела. Пароль `test1234`
для этой учетной записи не подходит.

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
