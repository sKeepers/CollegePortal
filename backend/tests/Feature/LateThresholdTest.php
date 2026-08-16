<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\ScheduleLesson;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Services\AttendanceAnalysisService;
use App\Services\SettingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Порог опоздания влияет на разбор посещаемости.
 *
 * Находка 3 аудита от 11.08.2026. Три настройки в каталоге были подписаны
 * «используется в аналитике посещаемости», а `statusForScheduledPerson` ставил
 * «Опоздал» всякому, кто вошёл позже начала занятия хоть на секунду. Порога там
 * не было вовсе: заместитель директора ставил 15 минут, сохранял — и ничего не
 * менялось. Настройка, которая ничего не делает, хуже отсутствующей: по ней
 * принимают решения.
 */
class LateThresholdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-10 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_student_inside_the_threshold_is_not_late(): void
    {
        $this->threshold('student_late_threshold_minutes', 15);
        $world = $this->world();

        // Вошёл через десять минут после начала — это меньше порога.
        $this->entry($world['studentIdentity'], '08:40');

        $row = $this->studentRow();

        $this->assertNotSame('late', $row['status'], 'Десять минут при пороге пятнадцать — не опоздание');
        $this->assertSame(10, $row['late_minutes'], 'Сами минуты остаются фактом и показываются человеку');
    }

    public function test_a_student_past_the_threshold_is_late(): void
    {
        $this->threshold('student_late_threshold_minutes', 15);
        $this->world();

        $this->entry(DigitalIdentity::query()->where('entity_type', 'student')->firstOrFail(), '08:50');

        $this->assertSame('late', $this->studentRow()['status']);
    }

    /**
     * Главное, ради чего всё и делалось: человек меняет настройку и видит
     * другой результат. Одни и те же данные, разный порог.
     */
    public function test_changing_the_setting_changes_the_verdict(): void
    {
        $world = $this->world();
        $this->entry($world['studentIdentity'], '08:40');

        $this->threshold('student_late_threshold_minutes', 5);
        $this->assertSame('late', $this->studentRow()['status'], 'При пороге пять минут десять — опоздание');

        $this->threshold('student_late_threshold_minutes', 30);
        $this->assertNotSame('late', $this->studentRow()['status'], 'При пороге тридцать те же десять — нет');
    }

    /**
     * У преподавателя порог свой, и по умолчанию он строже студенческого:
     * преподаватель ведёт занятие, его опоздание дороже.
     */
    public function test_the_teacher_has_a_threshold_of_their_own(): void
    {
        // Пороги разведены нарочно: если разбор возьмёт студенческий, вердикт
        // будет «Опоздал», и тест это поймает.
        $this->threshold('teacher_late_threshold_minutes', 30);
        $this->threshold('student_late_threshold_minutes', 1);
        $world = $this->world();

        $this->entry($world['teacherIdentity'], '08:40');

        $row = collect(app(AttendanceAnalysisService::class)->teachersToday()['data'])
            ->firstWhere('entity_id', $world['teacher']->id);

        $this->assertNotSame('late', $row['status'], 'Порог преподавателя не должен подменяться студенческим');
        $this->assertSame(10, $row['late_minutes'], 'Минуты остаются фактом и при своём пороге');
    }

    /**
     * Настройка, которую никто не читает, убрана из каталога, а не оставлена с
     * переписанной подписью: разбора посещаемости сотрудников не существует.
     */
    public function test_the_employee_threshold_is_gone_from_the_catalogue(): void
    {
        SettingService::ensureDefaults();

        $this->assertDatabaseMissing('settings', [
            'group' => 'attendance',
            'key' => 'employee_late_threshold_minutes',
        ]);

        $attendance = SettingService::definitions()['attendance'] ?? [];
        $this->assertArrayNotHasKey('employee_late_threshold_minutes', $attendance);
    }

    private function threshold(string $key, int $minutes): void
    {
        SettingService::ensureDefaults();

        Setting::query()->updateOrCreate(
            ['group' => 'attendance', 'key' => $key],
            ['value' => $minutes, 'type' => 'integer', 'is_public' => false],
        );
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $group = Group::query()->create([
            'name' => 'ИСП-101',
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);

        $student = Student::create([
            'group_id' => $group->id,
            'last_name' => 'Иванов',
            'first_name' => 'Дмитрий',
            'status' => 'active',
        ]);

        $teacher = Teacher::create([
            'last_name' => 'Кузьмина',
            'first_name' => 'Анастасия',
            'is_active' => true,
        ]);

        $subject = Subject::query()->create(['code' => 'MUS-1', 'name' => 'Сольфеджио']);

        ScheduleLesson::query()->create([
            'group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'lesson_date' => Carbon::today()->toDateString(),
            'starts_at' => '08:30',
            'ends_at' => '10:00',
        ]);

        return [
            'student' => $student,
            'teacher' => $teacher,
            'studentIdentity' => $this->identity('student', $student->id),
            'teacherIdentity' => $this->identity('teacher', $teacher->id),
        ];
    }

    private function identity(string $type, int $id): DigitalIdentity
    {
        return DigitalIdentity::create([
            'entity_type' => $type,
            'entity_id' => $id,
            'token' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'active',
            'issued_at' => now(),
        ]);
    }

    private function entry(DigitalIdentity $identity, string $time): void
    {
        AccessEvent::create([
            'digital_identity_id' => $identity->id,
            'entity_type' => $identity->entity_type,
            'entity_id' => $identity->entity_id,
            'direction' => AccessEvent::DIRECTION_IN,
            'event_time' => Carbon::today()->setTimeFromTimeString($time),
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);
    }

    /** @return array<string, mixed> */
    private function studentRow(): array
    {
        return collect(app(AttendanceAnalysisService::class)->studentsToday()['data'])->first();
    }
}
