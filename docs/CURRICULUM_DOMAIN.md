# Curriculum Domain

## Назначение

Curriculum Domain задает учебный план как источник истины для расписания, нагрузки, журнала, экзаменов, выпуска и будущих выгрузок ФРДО. ST-001A не ломает существующий модуль `/curricula`, а добавляет нормализованный слой `curriculum_subjects` рядом с legacy `curriculum_items`.

## Текущие сущности

- `curricula` - учебный план образовательной программы и года набора.
- `curriculum_items` - legacy строки плана: дисциплина, курс, семестр, общий объем часов, форма контроля строкой.
- `subjects` - справочник дисциплин.
- `groups` - учебные группы, теперь могут ссылаться на действующий `curriculum_id`.
- `teaching_loads` / `teaching_load_items` - нагрузка преподавателей, сейчас связана с преподавателями, дисциплинами и группами напрямую.
- `exams` / `exam_results` - экзамены и результаты, сейчас используют дисциплины, группы, преподавателей и аудитории напрямую.

## Нормализованная модель

Учебный план описывает цепочку:

Специальность -> квалификация -> год набора -> курс/семестр -> дисциплины -> часы -> тип занятий -> вид контроля -> компетенции -> преподаватели -> статус.

В ST-001A реализована foundation-часть:

- `curricula.qualification` - квалификация по плану;
- `curricula.competencies` - JSON-заготовка структуры компетенций;
- `curriculum_subjects` - нормализованные дисциплины семестра;
- `groups.curriculum_id` - ссылка группы на действующий учебный план.

## CurriculumSubject

`curriculum_subjects` хранит:

- `curriculum_id`;
- `semester`;
- `subject_id`;
- `lecture_hours`;
- `practice_hours`;
- `laboratory_hours`;
- `independent_hours`;
- `total_hours`;
- `control_type_id` и `control_type`;
- `sequence`;
- `is_optional`;
- `competencies`.

Уникальность: `curriculum_id + semester + subject_id`.

## Reference Data

Типы контроля вынесены в каталог `control_types`:

- `exam`;
- `credit`;
- `differentiated_credit`;
- `coursework`;
- `project`;
- `practice`;
- `gia`.

Типы занятий уже представлены каталогом `lesson_types` и будут использоваться следующими этапами расписания и нагрузки.

## Связи с другими доменами

- Schedule должен получать дисциплины семестра группы через `groups.curriculum_id`.
- Teaching Load должен связывать строки нагрузки с `curriculum_subjects` на следующем этапе.
- Journal должен опираться на занятия, созданные из плана и расписания.
- Exams должны использовать `control_types` и дисциплины учебного плана.
- Graduation/FRDO должны брать образовательную основу из учебного плана и результатов контроля.

## Что остается legacy

`curriculum_items` сохранен для обратной совместимости CSV и существующего UI/API. Новая разработка должна использовать `curriculum_subjects` и API Curriculum Engine.
