<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ScheduleLesson;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Своё расписание преподаватель разбирает без права на справочники колледжа.
 *
 * Экран расписания просил у портала четыре списка — группы, преподаватели,
 * дисциплины, аудитории — и получал у преподавателя четыре отказа 403: этих
 * прав у роли нет. Поля фильтра оставались пустыми и не объясняли почему.
 *
 * Права **не выдавались намеренно**, и выдавать их не нужно: занятия
 * преподавателю отдаются только свои (`ScheduleLessonController::applyScope`),
 * поэтому справочник всего колледжа экрану с собственными парами не нужен —
 * нужны значения, встречающиеся в этих парах. Хранилище берёт их из самих
 * занятий.
 *
 * Отсюда договор, который здесь и закреплён: **занятие обязано приходить с
 * вложенными группой, преподавателем, дисциплиной и аудиторией.** Уберут
 * `->with([...])` или урежут ресурс — и фильтры у преподавателя снова опустеют,
 * молча и не там, где правили.
 */
class TeacherScheduleNeedsNoDirectoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_teacher_sees_only_their_own_lessons_and_each_carries_its_references(): void
    {
        $mine = Teacher::create(['last_name' => 'Своя', 'first_name' => 'Пара', 'is_active' => true]);
        $other = Teacher::create(['last_name' => 'Чужая', 'first_name' => 'Пара', 'is_active' => true]);

        $group = Group::create(['name' => 'ТМ-1', 'specialty' => 'Теория музыки', 'course' => 1, 'year_start' => 2026]);
        $subject = Subject::create(['name' => 'Сольфеджио', 'code' => 'SOLF-1']);
        $classroom = Classroom::create(['number' => '301', 'building' => null]);

        foreach ([$mine, $other] as $teacher) {
            ScheduleLesson::create([
                'group_id' => $group->id,
                'teacher_id' => $teacher->id,
                'subject_id' => $subject->id,
                'classroom_id' => $classroom->id,
                'lesson_date' => '2026-09-01',
                'starts_at' => '08:00',
                'ends_at' => '08:45',
            ]);
        }

        $rows = $this->withApiAuth($this->teacherUser($mine))
            ->getJson('/api/schedule-lessons')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows, 'преподавателю отдаются только его занятия');

        foreach (['group', 'teacher', 'subject', 'classroom'] as $reference) {
            $this->assertIsArray($rows[0][$reference] ?? null,
                "занятие пришло без «{$reference}»: фильтр у преподавателя останется пустым");
            $this->assertNotNull($rows[0][$reference]['id'] ?? null);
        }
    }

    /** Тех самых четырёх прав у роли нет — и экран обязан работать без них. */
    public function test_the_directories_stay_closed_to_a_teacher(): void
    {
        $teacher = Teacher::create(['last_name' => 'Своя', 'first_name' => 'Пара', 'is_active' => true]);
        $this->withApiAuth($this->teacherUser($teacher));

        foreach (['groups', 'teachers', 'subjects', 'classrooms'] as $directory) {
            $this->getJson("/api/{$directory}")->assertForbidden();
        }
    }

    private function teacherUser(Teacher $teacher): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'teacher'], ['name' => 'Преподаватель']);
        $role->permissions()->sync(collect(['schedule.view', 'view_own_data'])->map(
            fn (string $code): int => Permission::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'module' => 'Schedule', 'description' => null, 'system' => true, 'active' => true],
            )->id,
        ));

        $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $teacher->forceFill(['user_id' => $user->id])->save();

        return $user;
    }
}
