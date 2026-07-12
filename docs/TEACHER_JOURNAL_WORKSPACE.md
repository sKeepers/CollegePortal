# Teacher Journal Workspace

ST-003B adds a daily workspace for teachers on top of the schedule-linked Journal Engine.

## Goal

The workspace lets a teacher open the journal for a concrete schedule lesson, fill topic and homework, mark attendance, enter grades, attach lesson files, complete the lesson and sign the journal without leaving `/journal`.

## Main Modes

`/journal` supports these modes:

- `Мои занятия` - today's lessons scoped by the current teacher.
- `Завтра` - tomorrow's lessons.
- `Текущая неделя` - lessons for the current week.
- `Завершенные` - completed and signed lessons.
- `Не заполненные` - draft, in progress, reopened or topicless lessons.
- `Подписанные` - signed lessons.
- `Контроль журналов` - study office/admin mode with `journal.view_all`.

Teachers see only their own `teacher_id` lessons. Study office, deputy and admin can use control mode when they have `journal.view_all`.

## Lesson Card

The right Workspace panel shows:

- discipline;
- date and time;
- group;
- teacher;
- classroom;
- lesson type;
- journal status;
- student count;
- present/absent counts;
- grade count;
- files;
- signature state.

## Student Table

The selected lesson opens a student table with:

- row number;
- photo placeholder;
- full name;
- attendance status;
- absence reason or minutes late;
- grade;
- grade comment.

Supported operations:

- mark all present;
- mark selected absent;
- edit individual attendance;
- edit individual grade;
- save attendance without page reload;
- save grades without page reload.

## Attendance Preview

The button `Предложить по данным проходной` requests the backend attendance suggestion. It returns a preview only:

- entered before lesson;
- entered late;
- not recorded;
- left before lesson end.

No attendance data is changed until the teacher applies the suggestion.

## Files

Lesson materials are uploaded to private storage through Journal Engine API. Supported MVP formats are PDF, JPG, PNG, DOCX, XLSX and PPTX. Files are downloaded only through authorized API routes.

## Signing Rules

Before signing, the lesson must have a topic and generated attendance rows. After signing:

- regular fields become read-only;
- the signature time and signer are displayed;
- corrections require `journal.reopen` and a reason;
- reopen is written to Audit.

## Statuses

- `draft` - draft lesson.
- `in_progress` - opened and being filled.
- `completed` - completed, waiting for signature.
- `signed` - signed and read-only.
- `reopened` - signed lesson reopened for correction.
- `cancelled` - cancelled lesson.

Legacy `planned` and `opened` remain accepted for compatibility.

## Exports

The workspace keeps lesson CSV export and adds period exports:

- `GET /api/journal/lessons/{id}/export.csv`
- `GET /api/journal/export/group.csv`
- `GET /api/journal/export/teacher.csv`

## Permissions

- `journal.view` - open journal pages and lesson data.
- `journal.view_all` - control mode for all teachers/groups.
- `journal.edit` - edit topic, homework and comments.
- `journal.attendance` - edit attendance and apply access-gate suggestions.
- `journal.grades` - edit grades.
- `journal.files` - upload/delete files.
- `journal.complete` - complete lesson.
- `journal.sign` - sign lesson.
- `journal.reopen` - reopen signed lesson with reason.
- `journal.export` - CSV export.

## Audit

Audit is written for topic/homework changes, attendance, grades, file upload/delete, completion, signature and reopen.
