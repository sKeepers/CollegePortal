<?php

namespace App\Services;

use App\Models\ScheduleLesson;
use App\Models\Student;
use Illuminate\Validation\ValidationException;

class JournalEntryService
{
    public function ensureStudentBelongsToLessonGroup(int $scheduleLessonId, int $studentId): void
    {
        $lesson = ScheduleLesson::query()->findOrFail($scheduleLessonId);
        $student = Student::query()->findOrFail($studentId);

        if ($student->group_id !== $lesson->group_id) {
            throw ValidationException::withMessages([
                'student_id' => ['The student does not belong to the lesson group.'],
            ]);
        }
    }
}
