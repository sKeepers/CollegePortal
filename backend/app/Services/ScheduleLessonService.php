<?php

namespace App\Services;

use App\DTO\ScheduleLessonData;
use App\Models\ScheduleLesson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ScheduleLessonService
{
    public function create(ScheduleLessonData $data): ScheduleLesson
    {
        $this->ensureNoConflicts($data);

        return ScheduleLesson::create($data->toArray());
    }

    public function update(ScheduleLesson $lesson, ScheduleLessonData $data): ScheduleLesson
    {
        $this->ensureNoConflicts($data, $lesson->id);
        $lesson->update($data->toArray());

        return $lesson;
    }

    private function ensureNoConflicts(ScheduleLessonData $data, ?int $ignoreLessonId = null): void
    {
        $errors = [];

        if ($this->hasConflict('group_id', $data->groupId, $data, $ignoreLessonId)) {
            $errors['group_id'][] = 'Группа уже занята в это время.';
        }

        if ($this->hasConflict('teacher_id', $data->teacherId, $data, $ignoreLessonId)) {
            $errors['teacher_id'][] = 'Преподаватель уже ведет занятие в это время.';
        }

        if ($data->classroomId !== null && $this->hasConflict('classroom_id', $data->classroomId, $data, $ignoreLessonId)) {
            $errors['classroom_id'][] = 'Аудитория уже занята в это время.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function hasConflict(string $column, int $value, ScheduleLessonData $data, ?int $ignoreLessonId): bool
    {
        return ScheduleLesson::query()
            ->where($column, $value)
            ->whereDate('lesson_date', $data->lessonDate)
            ->where('starts_at', '<', $data->endsAt)
            ->where('ends_at', '>', $data->startsAt)
            ->when($ignoreLessonId, fn (Builder $query) => $query->whereKeyNot($ignoreLessonId))
            ->exists();
    }
}
