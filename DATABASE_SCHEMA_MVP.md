# Структура базы данных MVP

## users

- id
- name
- email
- password
- role_id
- is_active
- created_at
- updated_at

## roles

- id
- name
- code
- description
- created_at
- updated_at

## permissions

- id
- name
- code
- description
- created_at
- updated_at

## groups

- id
- name
- specialty
- course
- year_start
- curator_id -> teachers.id nullable
- created_at
- updated_at

## students

- id
- user_id -> users.id nullable
- group_id -> groups.id
- last_name
- first_name
- middle_name nullable
- birth_date nullable
- phone nullable
- email nullable
- status
- enrollment_date nullable
- created_at
- updated_at

## teachers

- id
- user_id -> users.id nullable
- last_name
- first_name
- middle_name nullable
- phone nullable
- email nullable
- position nullable
- department nullable
- is_active
- created_at
- updated_at

## subjects

- id
- name
- code nullable
- department nullable
- description nullable
- created_at
- updated_at

## classrooms

- id
- number
- building nullable
- floor nullable
- capacity nullable
- type nullable
- description nullable
- created_at
- updated_at

## schedule_lessons

- id
- group_id -> groups.id
- teacher_id -> teachers.id
- subject_id -> subjects.id
- classroom_id -> classrooms.id nullable
- lesson_date
- starts_at
- ends_at
- lesson_type
- topic nullable
- created_at
- updated_at

Проверки:
- преподаватель не может вести два занятия в одно время;
- группа не может иметь два занятия в одно время;
- аудитория не может быть занята двумя занятиями в одно время.

## attendance

- id
- schedule_lesson_id -> schedule_lessons.id
- student_id -> students.id
- status
- comment nullable
- created_at
- updated_at

Статусы:
- present
- absent
- late
- excused

## grades

- id
- schedule_lesson_id -> schedule_lessons.id
- student_id -> students.id
- grade
- grade_type nullable
- comment nullable
- created_at
- updated_at
