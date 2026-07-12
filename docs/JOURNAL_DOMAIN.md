# Journal Domain

## Current Model

Before ST-003A CollegePortal had a legacy journal layer based on `schedule_lessons`, `attendance` and `grades`. That model stores attendance and grades against a legacy schedule lesson and is still preserved for compatibility with existing `/journal`, reports and legacy screens.

The Schedule Engine introduced `schedule_entries` as the normalized source of academic lessons. A schedule entry already contains the group, subject, teacher, date, time, classroom, lesson type and status. The journal domain now treats the schedule entry as the primary source for a concrete lesson.

## Limitations of Legacy Journal

- Attendance and grades are bound to `schedule_lessons`, not always to normalized Schedule Engine entries.
- Topic, homework, completion and signature lifecycle are weakly represented.
- File materials are not attached to a lesson.
- Teacher scope is not explicit enough for signed electronic journal workflows.

## Target Model

`journal_lessons` is the new aggregate root for one concrete journal lesson. It may reference:

- `schedule_entry_id` for normalized Schedule Engine lessons;
- `legacy_schedule_lesson_id` during transition.

The lesson stores copied operational fields needed for journal history: group, subject, teacher, date, time, lesson type, topic, homework, comment and status. Schedule remains the source for creating/opening the lesson; journal stores the pedagogical record.

## Related Entities

- `journal_attendance`: one row per student per journal lesson.
- `journal_grades`: grades by student and optional grade type.
- `journal_lesson_files`: private lesson materials.

## Transition Rules

- Existing legacy tables are not deleted.
- New Schedule Engine entries can be opened through `POST /api/journal/from-schedule/{scheduleEntryId}/open`.
- Repeated opening is idempotent.
- Cancelled schedule entries are not opened as normal journal lessons.
