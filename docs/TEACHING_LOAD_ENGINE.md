# Teaching Load Engine

## Назначение

Teaching Load Engine связывает Curriculum Engine с модулем нагрузки преподавателей. Он формирует плановые строки нагрузки для группы на учебный год на основе `groups.curriculum_id` и `curriculum_subjects`.

Генерация всегда идет через безопасный поток:

1. Preview без изменений в БД.
2. Apply после подтверждения.
3. Audit Log для фактических изменений.

## Модель данных

`teaching_loads` расширен nullable-полями:

- `curriculum_id`;
- `group_id`;
- `generated_at`;
- `generated_by`.

`teacher_id` стал nullable, чтобы сформированная из учебного плана нагрузка могла существовать до назначения преподавателей.

`teaching_load_items` расширен полями:

- `curriculum_subject_id`;
- `teacher_id`;
- `planned_hours`;
- `assigned_hours`;
- `unassigned_hours`;
- `overassigned_hours`;
- `workload_type_id`;
- `assignment_status`;
- `source`.

`source=curriculum_engine` используется для строк, созданных генератором. Ручные строки не удаляются и не перезаписываются генератором.

## Preview

`POST /api/teaching-load/generate/preview`

Вход:

- `group_id`;
- `academic_year`.

Preview показывает:

- группу;
- учебный план;
- учебный год;
- сколько строк найдено;
- сколько будет создано;
- сколько будет обновлено;
- конфликты с ручными строками;
- строки без преподавателя;
- предметы, семестры и плановые часы.

Preview не пишет Audit Log и не меняет БД.

## Apply

`POST /api/teaching-load/generate/apply`

Apply выполняется транзакционно:

- создает или переиспользует `teaching_load` для группы, учебного плана и учебного года;
- создает/обновляет строки `teaching_load_items` по `curriculum_subject_id`;
- выставляет `source=curriculum_engine`;
- рассчитывает `planned_hours`, `assigned_hours`, `unassigned_hours`, `overassigned_hours`, `assignment_status`;
- повторный apply не создает дубли.

## Назначение преподавателя

Автоматический подбор преподавателя не реализован. Поддержаны ручные операции:

- `POST /api/teaching-load/items/{id}/assign-teacher`;
- `POST /api/teaching-load/items/bulk-assign-teacher`.

Если у преподавателя есть список дисциплин и выбранная дисциплина в него не входит, система пишет audit warning, но не блокирует назначение.

## Coverage

`GET /api/teaching-load/{id}/coverage` возвращает:

- `planned_hours`;
- `assigned_hours`;
- `unassigned_hours`;
- `overassigned_hours`;
- количество строк по статусам.

Статусы распределения:

- `unassigned`;
- `partially_assigned`;
- `assigned`;
- `overassigned`.

## RBAC

Добавлены permissions:

- `teaching_load.generate`;
- `teaching_load.assign`;
- `teaching_load.bulk_assign`;
- `teaching_load.view_coverage`.

Матрица:

- `admin`: все;
- `deputy`, `study`, `academic_office`: generate, assign, bulk assign, coverage;
- `director`: coverage;
- `teacher`: просмотр нагрузки и coverage;
- `student`, `security`, `admission`: без доступа к генерации и назначению.

## Audit

Логируются:

- генерация нагрузки из учебного плана;
- создание строки генератором;
- повторное обновление строки генератором;
- назначение преподавателя;
- массовое назначение преподавателя;
- предупреждение о преподавателе, который не ведет дисциплину.

## Ограничения MVP

- Нет автоматического подбора преподавателя.
- Нет сложной проверки свободных часов преподавателя по другим нагрузкам.
- Нет распределения плановых часов по нескольким преподавателям в одной строке; строка хранит одного назначенного преподавателя.
- Генератор не удаляет ручные строки и не удаляет лишние generated-строки, если учебный план позднее сократился.
