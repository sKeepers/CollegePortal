# Учетные записи стенда DEV

## Назначение

Эти учетные записи предназначены только для проверки и демонстрации DEV-стенда CollegePortal.

DEV URL:

```text
https://192.168.34.114:5443/login
```

Снаружи тот же стенд открывается по адресу `https://84.54.208.134:5443/login`.

Порт `5174` — только локальный диагностический порт Vite на самом DEV-сервере, снаружи он не опубликован и по HTTPS не отвечает.

Мобильный сканер требует доверенного secure context, поэтому открывать его нужно тоже по HTTPS:

```text
https://192.168.34.114:5443/access/mobile-scanner
```

## Один набор на роль

До 10.08.2026 наборов было два: смоук-пользователи `admin@college-portal.local` с одним паролем
и параллельный набор `admin.uat@college-portal.local` с другим. Роль директора существовала дважды,
и перед входом приходилось вспоминать, какая из двух учетных записей сейчас нужна.
Теперь набор один: приставки UAT нет, домен `@local`, пароль общий.

| Роль | Email | Логин | Кто это на стенде |
| --- | --- | --- | --- |
| Администратор | `admin@local` | `admin` | служебная |
| Директор | `director@local` | `director` | названный сотрудник |
| Заместитель директора | `deputy@local` | `deputy` | служебная |
| Учебная часть 1 (расписание, нагрузка) | `study@local` | `study` | служебная |
| Учебная часть 2 (контингент, журнал) | `study.records@local` | `study.records` | названный сотрудник |
| Приемная комиссия | `admission@local` | `admission` | служебная |
| Отдел кадров | `hr@local` | `hr` | названный сотрудник |
| Преподаватель | `teacher@local` | `teacher` | демонстрационный преподаватель с расписанием и журналом |
| Студент | `student@local` | `student` | демонстрационный студент с группой и оценками |
| Сотрудник проходной | `security@local` | `security` | названный сотрудник |

Войти можно и по email, и по логину: `AuthController` принимает оба, а для учетных записей,
выданных автоматически, — еще и телефон.

Пароль задается переменной `DEMO_USER_PASSWORD` в `backend/.env` стенда и в документацию не выносится.
Значение по умолчанию для чистой установки — `test1234`, действующее значение стенда лежит в `.local/`.

## Как набор создается заново

```bash
php artisan db:seed --class=Database\\Seeders\\PortalUserSeeder
```

Сидер создает недостающие роли, выравнивает пароль и не трогает уже заполненные ФИО.
На стенде, где остались исторические адреса, их сначала сводят в один набор:

```bash
php artisan portal:merge-accounts          # план
php artisan portal:merge-accounts --apply  # применение
```

Учетные записи названных сотрудников заводятся отдельной командой `portal:staff-account`,
которая создает Person, карточку кадров, учетную запись с ролью и личный QR-пропуск.
Список конкретных людей с ФИО и телефонами лежит вне репозитория, в `.local/ops/`.

## Ограничения

- Использовать только на DEV.
- Не использовать в PROD: `PortalUserSeeder` в окружении `production` ничего не создает.
- Перед production deployment пароль должен быть заменен или учетные записи отключены.
- Реальные учетные записи студентов и сотрудников не должны использоваться для smoke-тестов.

## Smoke routes по ролям

| Роль | Проверяемые страницы |
| --- | --- |
| Администратор | `/dashboard`, `/admissions/foundation`, `/identity/digital-passes`, `/access/gate`, `/access/mobile-scanner`, `/access/reports` |
| Директор | `/dashboard`, `/students`, `/schedule`, `/journal`, `/access/reports` |
| Отдел кадров | `/dashboard`, `/hr/employees`, `/hr/calendar` |
| Учебная часть 2 | `/dashboard`, `/students`, `/groups`, `/journal`, `/graduation` |
| Приемная комиссия | `/dashboard`, `/admissions/foundation` |
| Проходная | `/dashboard`, `/access/gate`, `/access/mobile-scanner`, `/access/reports` |
| Преподаватель | `/dashboard`, `/journal`, `/identity/my-pass` |
| Студент | `/dashboard`, `/identity/my-pass`, `/m/student/pass` |

Обычные `teacher` и `student` не должны видеть административные страницы `/identity/digital-passes`, `/access/gate`, `/access/mobile-scanner` и `/access/reports`.
