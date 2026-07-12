# Schedule Engine

## Цель

Schedule Engine — foundation для безопасного создания, проверки и применения расписания на основе учебных планов и нагрузки преподавателей.

## Источники данных

- `curriculum_subjects` задают дисциплины и часы учебного плана;
- `teaching_load_items` задают допустимые пары группа-дисциплина-преподаватель;
- `groups` задают учебную группу и размер контингента;
- `teachers` задают преподавателей;
- `classrooms` задают аудитории и вместимость;
- `reference_items` каталога `lesson_types` задают виды занятий.

## Поток операции

Любая новая запись расписания проходит поток:

1. `preview` — расчет записи и конфликтов без изменения БД;
2. `validate` — тот же механизм проверки для внешних клиентов;
3. `apply` — транзакционное сохранение только если нет blocking-конфликтов;
4. audit log — запись изменения;
5. синхронизация `schedule_lessons` для совместимости.

## API

- `POST /api/schedule/preview`;
- `POST /api/schedule/validate`;
- `POST /api/schedule/apply`;
- `GET /api/schedule/conflicts`;
- `GET /api/schedule/coverage`;
- `GET /api/schedule/group/{groupId}`;
- `GET /api/schedule/teacher/{teacherId}`;
- `GET /api/schedule/classroom/{classroomId}`;
- `POST /api/schedule/entries/{id}/replace-teacher`;
- `POST /api/schedule/entries/{id}/replace-classroom`;
- `POST /api/schedule/entries/{id}/move`;
- `POST /api/schedule/entries/{id}/cancel`;
- `POST /api/schedule/entries/{id}/restore`.

## Правила конфликтов

Blocking:

- преподаватель занят в это время;
- группа занята в это время;
- аудитория занята в это время;
- некорректное время;
- дубль записи;
- дисциплина отсутствует в нагрузке группы;
- выбранная строка нагрузки не соответствует группе или дисциплине;
- преподаватель не соответствует назначению в нагрузке.

Warning:

- аудитория меньше группы;
- преподаватель еще не назначен в строке нагрузки;
- часы расписания превышают плановые часы нагрузки.

## Контроль часов

Для `teaching_load_item` engine считает:

- `planned_hours`;
- `scheduled_hours`;
- `remaining_hours`;
- `over_scheduled_hours`.

Статусы:

- `not_scheduled`;
- `partially_scheduled`;
- `scheduled`;
- `over_scheduled`.

## Замены и переносы

ST-002A поддерживает ручные операции:

- замена преподавателя;
- замена аудитории;
- перенос занятия;
- отмена;
- восстановление.

Каждая операция проходит validation, сохраняет связь с исходной записью через `replaced_entry_id` и пишется в Audit.

## Ограничения MVP

- автоматический подбор преподавателей не реализован;
- автоматическая генерация недельного расписания не реализована;
- шаблоны созданы как структура данных для следующего этапа;
- старые `schedule_lessons` остаются частью совместимости.

## Visual Editor (ST-002B)

Visual Editor использует Schedule Engine как единственный путь изменения данных: создание, drag & drop перенос и применение шаблона проходят preview/validation/apply. Drop в сетке не сохраняет данные напрямую.

Добавлены template endpoints для MVP:

- `GET /api/schedule/templates`;
- `POST /api/schedule/templates`;
- `POST /api/schedule/templates/{id}/apply-preview`;
- `POST /api/schedule/templates/{id}/apply`.

## ST-003A Journal Integration

Schedule Engine entries can now be opened as electronic journal lessons. Journal Engine stores topic, homework, attendance, grades, private files and signature status while preserving the schedule entry as the source of group, subject, teacher, date and time. Access is controlled by `journal.*` permissions and all mutating journal actions are audited.

## HR warnings

Schedule Engine учитывает кадровые статусы преподавателей read-only. Если связанный Employee находится в отпуске, на больничном, в командировке, отстранен или уволен на дату занятия, preview возвращает warning `teacher_hr_unavailable`. Система не отменяет занятие автоматически.

## HR replacements

HR replacement flow использует существующий Schedule Engine replacement endpoint. Для `is_replacement=true` несоответствие преподавателя строке нагрузки считается warning, а не blocking, потому что замена намеренно отличается от исходного назначения.
