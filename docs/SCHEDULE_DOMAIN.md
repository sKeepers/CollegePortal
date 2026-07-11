# Schedule Domain

## Назначение

Schedule Domain отвечает за планирование занятий колледжа и связывает расписание с учебными планами, нагрузкой преподавателей, журналом, аудиториями и аналитикой посещаемости.

## Текущая модель до ST-002A

До появления Schedule Engine расписание хранилось в `schedule_lessons`:

- `group_id`;
- `teacher_id`;
- `subject_id`;
- `classroom_id`;
- `lesson_date`;
- `starts_at`;
- `ends_at`;
- `lesson_type` строкой;
- `topic`.

Эта таблица остается рабочей для существующих модулей журнала, оценок, посещаемости и legacy-compatible экранов. Данные не удаляются.

## Ограничения старой модели

- нет связи с `teaching_load_items`;
- нет нормализованного типа занятия через Reference Data;
- нет недельных шаблонов;
- нет четной/нечетной недели;
- нет модели замен, отмен и переносов;
- конфликт проверяется только на уровне базовых пересечений;
- нельзя оценить покрытие часов нагрузки расписанием.

## Нормализованная модель ST-002A

Новая таблица `schedule_entries` становится foundation-слоем Schedule Engine.

Ключевые поля:

- `academic_year`;
- `semester`;
- `date` для разового занятия;
- `day_of_week` для недельного шаблона;
- `week_type`: `all`, `even`, `odd`;
- `lesson_number`;
- `starts_at`, `ends_at`;
- `group_id`;
- `subject_id`;
- `teacher_id`;
- `classroom_id`;
- `teaching_load_item_id`;
- `lesson_type_id` из справочника `lesson_types`;
- `status`: `draft`, `scheduled`, `canceled`, `moved`;
- `source`;
- `is_replacement`;
- `replaced_entry_id`;
- `created_by`, `updated_by`.

## Совместимость

При применении `schedule_entries` с конкретной датой engine синхронно создает или обновляет запись `schedule_lessons`. Это сохраняет работу существующего `/schedule`, журнала, оценок и посещаемости.

## Шаблоны

Для будущего недельного планирования добавлены таблицы:

- `schedule_templates`;
- `schedule_template_entries`.

В ST-002A они являются foundation-структурой. Сложный автогенератор расписания не реализуется.

## План перехода

1. Новые операции расписания проходят через Schedule Engine: preview, validation, apply.
2. Старые `schedule_lessons` остаются для чтения и совместимости.
3. Новые занятия связываются с `teaching_load_items`.
4. После стабилизации журнала и отчетов можно переводить чтение на `schedule_entries`.
5. Автоматическая генерация расписания остается отдельным будущим этапом.

## Visual editing (ST-002B)

Недельная сетка является UI-представлением `schedule_entries` и совместимых `schedule_lessons`. Новые engine-записи синхронизируются в legacy-таблицу, поэтому журнал и существующие представления продолжают работать.
