<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Group;
use App\Models\ScheduleLesson;
use App\Models\Subject;
use App\Models\Teacher;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleLessonApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_lists_schedule_lessons(): void
    {
        $context = $this->createContext();
        $lesson = $this->createLesson($context);

        $this->getJson("/api/schedule-lessons?group_id={$context['group']->id}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $lesson->id)
            ->assertJsonPath('data.0.starts_at', '09:00');
    }

    public function test_it_lists_schedule_lessons_with_subject_filter(): void
    {
        $context = $this->createContext();
        $lesson = $this->createLesson($context);
        $otherSubject = Subject::create([
            'name' => 'Painting',
            'code' => 'ART-101',
        ]);

        ScheduleLesson::create([
            'group_id' => $context['group']->id,
            'teacher_id' => $context['teacher']->id,
            'subject_id' => $otherSubject->id,
            'classroom_id' => $context['classroom']->id,
            'lesson_date' => '2026-09-03',
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'lesson_type' => 'lesson',
        ]);

        $this->getJson("/api/schedule-lessons?subject_id={$context['subject']->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lesson->id);
    }

    public function test_it_creates_schedule_lesson(): void
    {
        $context = $this->createContext();

        $response = $this->postJson('/api/schedule-lessons', [
            'group_id' => $context['group']->id,
            'teacher_id' => $context['teacher']->id,
            'subject_id' => $context['subject']->id,
            'classroom_id' => $context['classroom']->id,
            'lesson_date' => '2026-09-02',
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'lesson_type' => 'lesson',
            'topic' => 'Introduction to music notation',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.group_id', $context['group']->id)
            ->assertJsonPath('data.starts_at', '09:00');

        $this->assertDatabaseHas('schedule_lessons', [
            'group_id' => $context['group']->id,
            'starts_at' => '09:00',
        ]);
    }

    public function test_it_rejects_group_time_conflict(): void
    {
        $context = $this->createContext();
        $this->createLesson($context);

        $otherTeacher = Teacher::create(['last_name' => 'Sidorov', 'first_name' => 'Ivan']);
        $otherClassroom = Classroom::create(['number' => '305', 'building' => 'Main']);

        $this->postJson('/api/schedule-lessons', [
            'group_id' => $context['group']->id,
            'teacher_id' => $otherTeacher->id,
            'subject_id' => $context['subject']->id,
            'classroom_id' => $otherClassroom->id,
            'lesson_date' => '2026-09-02',
            'starts_at' => '10:00',
            'ends_at' => '11:30',
            'lesson_type' => 'lesson',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('group_id');
    }

    public function test_it_rejects_teacher_time_conflict(): void
    {
        $context = $this->createContext();
        $this->createLesson($context);

        $otherGroup = Group::create([
            'name' => 'D-101',
            'specialty' => 'Design',
            'course' => 1,
            'year_start' => 2026,
        ]);
        $otherClassroom = Classroom::create(['number' => '305', 'building' => 'Main']);

        $this->postJson('/api/schedule-lessons', [
            'group_id' => $otherGroup->id,
            'teacher_id' => $context['teacher']->id,
            'subject_id' => $context['subject']->id,
            'classroom_id' => $otherClassroom->id,
            'lesson_date' => '2026-09-02',
            'starts_at' => '10:00',
            'ends_at' => '11:30',
            'lesson_type' => 'lesson',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('teacher_id');
    }

    public function test_it_rejects_classroom_time_conflict(): void
    {
        $context = $this->createContext();
        $this->createLesson($context);

        $otherGroup = Group::create([
            'name' => 'D-101',
            'specialty' => 'Design',
            'course' => 1,
            'year_start' => 2026,
        ]);
        $otherTeacher = Teacher::create(['last_name' => 'Sidorov', 'first_name' => 'Ivan']);

        $this->postJson('/api/schedule-lessons', [
            'group_id' => $otherGroup->id,
            'teacher_id' => $otherTeacher->id,
            'subject_id' => $context['subject']->id,
            'classroom_id' => $context['classroom']->id,
            'lesson_date' => '2026-09-02',
            'starts_at' => '10:00',
            'ends_at' => '11:30',
            'lesson_type' => 'lesson',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('classroom_id');
    }

    public function test_it_updates_schedule_lesson(): void
    {
        $context = $this->createContext();
        $lesson = $this->createLesson($context);

        $this->patchJson("/api/schedule-lessons/{$lesson->id}", [
            'starts_at' => '11:00',
            'ends_at' => '12:30',
            'topic' => 'Rhythm basics',
        ])
            ->assertOk()
            ->assertJsonPath('data.starts_at', '11:00')
            ->assertJsonPath('data.topic', 'Rhythm basics');
    }

    public function test_it_deletes_schedule_lesson(): void
    {
        $context = $this->createContext();
        $lesson = $this->createLesson($context);

        $this->deleteJson("/api/schedule-lessons/{$lesson->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('schedule_lessons', ['id' => $lesson->id]);
    }

    public function test_teacher_and_student_only_receive_their_own_schedule_scope(): void
    {
        $this->seed(RoleSeeder::class);
        $first = $this->createContext();
        $firstLesson = $this->createLesson($first);
        $second = $this->createContext();
        $secondLesson = $this->createLesson($second);

        $teacherUser = $this->createApiUser(roleCode: 'teacher');
        $first['teacher']->forceFill(['user_id' => $teacherUser->id])->save();

        $studentUser = $this->createApiUser(roleCode: 'student');
        Student::create([
            'user_id' => $studentUser->id,
            'group_id' => $second['group']->id,
            'last_name' => 'Тестовый',
            'first_name' => 'Студент',
            'status' => 'active',
        ]);

        $this->withApiAuth($teacherUser)
            ->getJson('/api/schedule-lessons')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $firstLesson->id);

        $this->withApiAuth($studentUser)
            ->getJson('/api/schedule-lessons')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $secondLesson->id);
    }

    private function createContext(): array
    {
        return [
            'group' => Group::create([
                'name' => 'M-101',
                'specialty' => 'Instrumental Performance',
                'course' => 1,
                'year_start' => 2026,
            ]),
            'teacher' => Teacher::create([
                'last_name' => 'Petrov',
                'first_name' => 'Alexey',
            ]),
            'subject' => Subject::create([
                'name' => 'Music Theory',
                'code' => 'MUS-101',
            ]),
            'classroom' => Classroom::create([
                'number' => '201',
                'building' => 'Main',
            ]),
        ];
    }

    private function createLesson(array $context): ScheduleLesson
    {
        return ScheduleLesson::create([
            'group_id' => $context['group']->id,
            'teacher_id' => $context['teacher']->id,
            'subject_id' => $context['subject']->id,
            'classroom_id' => $context['classroom']->id,
            'lesson_date' => '2026-09-02',
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'lesson_type' => 'lesson',
            'topic' => 'Introduction',
        ]);
    }
}
