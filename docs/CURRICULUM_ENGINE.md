# Curriculum Engine

## Назначение

Curriculum Engine - backend foundation, который возвращает нормализованные дисциплины учебного плана, семестры и итоги. Он пока не генерирует расписание, но подготавливает единый источник данных для расписания, нагрузки, журнала, экзаменов, дипломов и ФРДО.

## API

- `GET /api/curricula/{id}/subjects` - дисциплины учебного плана;
- `GET /api/curricula/{id}/semesters` - группировка дисциплин по семестрам;
- `GET /api/curricula/{id}/summary` - автоматические итоги;
- `POST /api/curricula/{id}/subjects` - добавить дисциплину семестра;
- `PUT /api/curriculum-subjects/{id}` - изменить часы, контроль, порядок;
- `DELETE /api/curriculum-subjects/{id}` - удалить дисциплину семестра.

## Итоги

Summary считает:

- всего дисциплин;
- всего часов;
- лекции;
- практические часы;
- лабораторные часы;
- самостоятельные часы;
- экзамены;
- зачеты;
- дифференцированные зачеты;
- практики;
- курсовые и проекты;
- ГИА.

## Сервис

`CurriculumEngineService` предоставляет:

- `summary(Curriculum $curriculum)`;
- `semesters(Curriculum $curriculum)`;
- `subjectsForGroup(Group $group, ?int $semester = null)`.

`subjectsForGroup` использует `groups.curriculum_id` и возвращает дисциплины семестра без копирования дисциплин в группу.

## RBAC

Добавлены permissions:

- `curricula.subjects.view`;
- `curricula.subjects.create`;
- `curricula.subjects.update`;
- `curricula.subjects.delete`.

`director` получает просмотр, `deputy/study/academic_office` получают ведение, `admin` имеет полный доступ.

## Audit

Логируются события:

- `curriculum_subject_created`;
- `curriculum_subject_updated`;
- `curriculum_subject_deleted`.

Audit фиксирует изменения часов, вида контроля, порядка, optional-флага и компетенций.

## Frontend

Карточка `/curricula` получила вкладки:

- `Общее`;
- `Семестры`;
- `Дисциплины`;
- `Контроль`;
- `Итоги`.

Вкладки используют новый API Curriculum Engine. Legacy CSV и старые строки `curriculum_items` остаются совместимыми.

## Ограничения ST-001A

- Преподаватели пока не назначаются на `curriculum_subjects`.
- Типы занятий пока не распределяются по строке плана, только подготовлен Reference Data слой.
- Расписание не генерируется автоматически.
- Teaching Load и Exams пока не переведены на прямую FK-связь с `curriculum_subjects`.

## ST-001B: связь с Teaching Load

Teaching Load Engine использует `groups.curriculum_id` и `curriculum_subjects`, чтобы сформировать строки нагрузки группы на учебный год. Curriculum Engine остается источником плановых дисциплин и часов; Teaching Load хранит результат распределения и назначение преподавателей.
