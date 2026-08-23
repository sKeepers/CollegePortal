<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\JournalLesson;
use App\Models\ScheduleEntry;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingLoad;
use App\Models\TeachingLoadItem;
use App\Services\JournalService;
use App\Services\ScheduleEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Замена преподавателя должна доходить до журнала.
 *
 * Заболевший преподаватель на первой неделе — норма, а не редкость. Пока замена
 * меняла только расписание, занятие уходило из дня заболевшего, приходило к
 * замещающему, а журнал оставался за первым: отметить не мог никто.
 */
class ScheduleReplacementJournalTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_journal_follows_the_replacement(): void
    {
        [$entry, $main, $substitute] = $this->lesson();

        $journal = app(JournalService::class)->openFromSchedule($entry, $main->user ?? $this->userFor($main));
        $this->assertSame($main->id, (int) $journal->teacher_id);

        app(ScheduleEngineService::class)->replaceTeacher($entry, $substitute->id);

        $journal->refresh();
        $this->assertSame($substitute->id, (int) $journal->teacher_id, 'Журнал остался за прежним преподавателем: отметить не сможет никто.');
    }

    public function test_signed_journal_is_left_alone(): void
    {
        [$entry, $main, $substitute] = $this->lesson();

        $journal = app(JournalService::class)->openFromSchedule($entry, $this->userFor($main));
        $journal->forceFill(['status' => JournalLesson::STATUS_SIGNED])->save();

        app(ScheduleEngineService::class)->replaceTeacher($entry, $substitute->id);

        $this->assertSame($main->id, (int) $journal->refresh()->teacher_id, 'Подписанный журнал правится только через переоткрытие.');
    }

    /** @return array{0: ScheduleEntry, 1: Teacher, 2: Teacher} */
    private function lesson(): array
    {
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        $subject = Subject::create(['name' => 'Сольфеджио']);
        $main = Teacher::create(['last_name' => 'Основнов', 'first_name' => 'Олег', 'is_active' => true]);
        $substitute = Teacher::create(['last_name' => 'Заменов', 'first_name' => 'Захар', 'is_active' => true]);
        Student::create(['group_id' => $group->id, 'last_name' => 'Абрамов', 'first_name' => 'Пётр', 'status' => 'active']);

        $load = TeachingLoad::create(['group_id' => $group->id, 'academic_year' => '2026-2027', 'status' => 'draft']);
        TeachingLoadItem::create([
            'teaching_load_id' => $load->id, 'group_id' => $group->id, 'subject_id' => $subject->id,
            'teacher_id' => $main->id, 'semester' => 1, 'hours_total' => 72, 'planned_hours' => 72,
        ]);

        $entry = app(ScheduleEngineService::class)->apply([
            'group_id' => $group->id, 'subject_id' => $subject->id, 'teacher_id' => $main->id,
            'date' => '2026-09-01', 'starts_at' => '08:30', 'ends_at' => '10:05', 'lesson_number' => 1,
        ])['entry'];

        return [$entry, $main, $substitute];
    }

    private function userFor(Teacher $teacher): \App\Models\User
    {
        return \App\Models\User::factory()->create();
    }
}
