<?php

namespace App\DTO;

class ScheduleLessonData
{
    public function __construct(
        public readonly int $groupId,
        public readonly int $teacherId,
        public readonly int $subjectId,
        public readonly ?int $classroomId,
        public readonly string $lessonDate,
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly string $lessonType,
        public readonly ?string $topic,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            groupId: $data['group_id'],
            teacherId: $data['teacher_id'],
            subjectId: $data['subject_id'],
            classroomId: $data['classroom_id'] ?? null,
            lessonDate: $data['lesson_date'],
            startsAt: $data['starts_at'],
            endsAt: $data['ends_at'],
            lessonType: $data['lesson_type'] ?? 'lesson',
            topic: $data['topic'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'group_id' => $this->groupId,
            'teacher_id' => $this->teacherId,
            'subject_id' => $this->subjectId,
            'classroom_id' => $this->classroomId,
            'lesson_date' => $this->lessonDate,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'lesson_type' => $this->lessonType,
            'topic' => $this->topic,
        ];
    }
}
