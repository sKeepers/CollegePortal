# Настройки CollegePortal

## Назначение

Модуль настроек хранит административные и публичные параметры колледжа в таблице `settings`, чтобы ключевые значения не были разбросаны по `.env`, config, сидерам и markdown-документам.

Модуль не предназначен для хранения секретов. Пароли, API-ключи, токены интеграций, закрытые сертификаты и другие чувствительные значения должны храниться в защищенном secret storage или `.env` и подключаться отдельной архитектурной задачей.

## Backend

Таблица `settings`:

- `group` - группа настроек;
- `key` - ключ внутри группы;
- `value` - JSON-значение;
- `type` - тип значения для интерфейса и нормализации;
- `is_public` - можно ли отдавать значение через публичный API;
- `description` - описание настройки.

Уникальность задается парой `group + key`.

Основные классы:

- `Setting` - Eloquent-модель;
- `SettingService` - дефолты, чтение, обновление, сброс;
- `AdminSettingController` - административный и публичный API;
- `SettingResource` - единое представление настройки.

## API

Административные endpoints:

- `GET /api/admin/settings` - список всех настроек по группам;
- `PUT /api/admin/settings` - сохранение настроек или сброс к значениям по умолчанию.

Публичный endpoint:

- `GET /api/settings/public` - только настройки с `is_public=true`.

Изменение production-настроек требует отдельного подтверждения через `confirm_production=true`. По умолчанию production-изменения блокируются.

## Группы настроек

### general

- `college_full_name` - публичная;
- `college_short_name` - публичная;
- `college_address` - публичная;
- `college_phone` - публичная;
- `college_email` - публичная;
- `college_website` - публичная.

### academic

- `current_academic_year`;
- `current_semester`;
- `lesson_duration_minutes`;
- `default_week_start`.

### admissions

- `current_admission_campaign`;
- `max_applications_per_applicant`.

### graduation

- `current_graduation_year`;
- `diploma_series_default`.

### identity

- `digital_pass_default_days`;
- `duplicate_scan_window_seconds` - публичная, используется проходной для защиты от повторного скана.

### integrations

- `frdo_mode`;
- `fis_mode`.

### branding

- `logo_path` - публичная;
- `favicon_path` - публичная;
- `primary_color` - публичная.

## Frontend

Раздел `/admin/settings` расположен в меню `Система -> Настройки колледжа` и доступен пользователям с правом `manage_users`.

Интерфейс разбит на вкладки:

- Общие;
- Учебный процесс;
- Приемная комиссия;
- Выпуск;
- Идентификация;
- Интеграции;
- Брендинг.

Публичные настройки используются в интерфейсе для названия колледжа, подписи рабочего места и логотипа. Проходная использует `identity.duplicate_scan_window_seconds` вместо жестко заданных 2 секунд.

## Что нельзя хранить plain text

В `settings.value` нельзя хранить:

- пароли;
- токены API;
- OAuth client secret;
- закрытые ключи;
- сертификаты;
- production-доступы к ФРДО, ФИС, Moodle, LDAP/AD;
- персональные данные, которые не являются настройками системы.

Для таких значений нужен отдельный механизм секретов с шифрованием и аудитом доступа.
