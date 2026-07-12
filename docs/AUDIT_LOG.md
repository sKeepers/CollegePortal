# AUDIT_LOG: централизованный аудит CollegePortal

Дата: 07.07.2026.
Окружение реализации: DEV `/srv/college-dev`.

## Назначение

Audit Log Platform — единый механизм фиксации действий пользователей в CollegePortal. Он нужен для администрирования, разбора инцидентов, подготовки к UAT и будущей эксплуатации платформы.

## Архитектура

Основные элементы:

- `audit_logs` — таблица событий;
- `AuditLog` — Eloquent-модель;
- `AuditLogService` — единая точка записи событий;
- `AuditLogResource` — API-представление события;
- `AuditLogController` — чтение журнала аудита;
- `/admin/audit` — frontend-раздел просмотра событий.

## Таблица audit_logs

Поля:

- `id`;
- `created_at`;
- `user_id` nullable;
- `person_id` nullable;
- `action`;
- `entity_type`;
- `entity_id`;
- `module`;
- `old_values` JSON nullable;
- `new_values` JSON nullable;
- `ip_address`;
- `user_agent`;
- `request_id` nullable.

## Как подключать новые модули

В любом backend-модуле нужно вызвать:

```php
AuditLogService::log(
    module: 'students',
    action: 'update',
    entity: $student,
    old: $oldValues,
    new: $student->fresh()->getAttributes(),
    request: $request,
);
```

Модуль не должен знать структуру таблицы `audit_logs`. Он передает только смысл события: модуль, действие, объект, старое и новое значение.

## Что логируется в CORE-002

- вход пользователя;
- выход пользователя;
- создание пользователя;
- редактирование пользователя;
- удаление пользователя;
- блокировка пользователя;
- разблокировка пользователя;
- назначение ролей;
- создание роли;
- редактирование роли;
- удаление роли;
- предпросмотр импорта;
- проверка импорта;
- подтверждение импорта;
- скачивание шаблона импорта;
- выпуск QR-пропуска;
- отзыв QR-пропуска;
- создание demo-данных;
- очистка demo-данных;
- импорт demo-файла;
- экспорт demo-сводки.

## Что пока не логируется

- все CRUD-действия справочников и учебных модулей;
- просмотр страниц;
- чтение отдельных карточек;
- неуспешные попытки входа;
- массовые действия, если они не проходят через уже подключенные контроллеры;
- действия мобильного кабинета студента.

Эти события можно подключать постепенно через `AuditLogService::log()`.

## Безопасность

`AuditLogService` удаляет из `old_values` и `new_values` чувствительные поля:

- `password`;
- `remember_token`;
- `api_token_hash`;
- `token`.

Доступ к `/api/admin/audit` и `/admin/audit` ограничен permission `manage_users`.

## Frontend

Раздел `/admin/audit` показывает:

- дату;
- модуль;
- пользователя;
- действие;
- объект;
- IP;
- фильтры по пользователю, модулю, действию, периоду и поиску;
- карточку события с pretty JSON для старых и новых значений;
- кнопку `Открыть объект`, если объект можно связать с существующим разделом.

## BULK-001: аудит массовых операций

`/api/admissions/bulk/apply` и `/api/students/bulk/apply` пишут одну агрегированную запись Audit Log на операцию. В `new_values` сохраняется безопасный отчет: действие, выбранное количество, изменено, пропущено, ошибки и первые примеры без паспортных данных, адресов и полных чувствительных идентификаторов.


## ADM-DOCS-001: аудит документов абитуриента

Registry документов заявления пишет audit для действий приема, загрузки файла, удаления файла, проверки, отклонения и bulk-операций по типам документов. В audit сохраняются тип документа, статус, безопасные метаданные файла и причина отклонения. Содержимое файлов, приватные storage paths и чувствительные персональные данные в audit не пишутся.

## ST-001B: аудит генерации нагрузки

Teaching Load Engine пишет audit для apply-генерации, создания/обновления generated-строк, ручного назначения преподавателя, массового назначения и предупреждения о назначении преподавателя на дисциплину вне его профиля. Preview не логируется как изменение.

## Schedule Engine audit (ST-002A)

Schedule Engine логирует создание занятия, изменение, замену преподавателя, замену аудитории, перенос, отмену и восстановление. Preview/validation не пишут Audit, потому что не изменяют данные.

## Visual Schedule Editor audit (ST-002B)

Drag & drop перенос фиксируется как `schedule_entry_moved`, применение шаблона как `schedule_template_applied`, создание шаблона как `schedule_template_created`. Preview не логируется как изменение.

## ST-003A Journal Integration

Schedule Engine entries can now be opened as electronic journal lessons. Journal Engine stores topic, homework, attendance, grades, private files and signature status while preserving the schedule entry as the source of group, subject, teacher, date and time. Access is controlled by `journal.*` permissions and all mutating journal actions are audited.
