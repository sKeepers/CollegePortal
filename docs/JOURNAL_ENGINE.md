# Journal Engine

## Purpose

Journal Engine links the electronic journal to concrete Schedule Engine lessons and provides the lifecycle for topic, homework, attendance, grades, materials, completion and signature.

## Data Model

- `journal_lessons`: lesson header and lifecycle.
- `journal_attendance`: attendance status per student.
- `journal_grades`: grade value per student and optional Reference Data grade type.
- `journal_lesson_files`: private files attached to a lesson.

## Opening a Lesson

The journal is opened from `schedule_entries`. On first open the service creates one `journal_lesson` and automatically creates attendance rows for active students in the group. Repeated open returns the same lesson and does not duplicate rows.

## Attendance Rules

Supported statuses:

- `present`;
- `absent`;
- `late`;
- `excused`;
- `sick`;
- `remote`.

The access gate can provide a read-only suggestion. The suggestion does not change the database until the teacher explicitly applies it.

## Grade Rules

MVP grade values:

- `2`, `3`, `4`, `5`;
- `зачет`;
- `незачет`;
- empty value for no grade.

Grade types are prepared through Reference Data via nullable `grade_type_id`.

## Signature Rules

Statuses:

- `planned`;
- `opened`;
- `completed`;
- `signed`;
- `cancelled`.

After signing, regular edits are blocked. Corrections require `journal.reopen`; all changes are logged in Audit.

## API

- `GET /api/journal/lessons`
- `GET /api/journal/lessons/{id}`
- `POST /api/journal/from-schedule/{scheduleEntryId}/open`
- `PUT /api/journal/lessons/{id}`
- `POST /api/journal/lessons/{id}/complete`
- `POST /api/journal/lessons/{id}/sign`
- `PUT /api/journal/lessons/{id}/attendance`
- `PUT /api/journal/lessons/{id}/grades`
- `GET /api/journal/lessons/{id}/attendance-suggestion`
- `POST /api/journal/lessons/{id}/attendance-suggestion/apply`
- `POST /api/journal/lessons/{id}/files`
- `GET /api/journal/lessons/{id}/files/{fileId}/download`
- `DELETE /api/journal/lessons/{id}/files/{fileId}`
- `GET /api/journal/lessons/{id}/export.csv`

## Permissions

- `journal.view`
- `journal.edit`
- `journal.attendance`
- `journal.grades`
- `journal.files`
- `journal.complete`
- `journal.sign`
- `journal.reopen`
- `journal.export`
- `journal.view_all`

Teacher access is scoped to own `teacher_id`. Student access is read-only and scoped to own group/data.

## ST-003B Teacher Journal Workspace

`/journal` now provides teacher-focused modes for today, tomorrow, current week, completed, not filled and signed lessons. A `journal.view_all` control mode is available for study/deputy/admin roles. The selected lesson exposes an editable student table, attendance suggestion preview from access events, lesson files, completion, signature and reopen workflow.

Additional API:

- `POST /api/journal/lessons/{id}/reopen`
- `GET /api/journal/export/group.csv`
- `GET /api/journal/export/teacher.csv`

The accepted status set now includes `draft`, `in_progress`, `completed`, `signed`, `reopened` and `cancelled`; legacy `planned/opened` are kept for compatibility.
