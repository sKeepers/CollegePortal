<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\Employee;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\AttendanceAnalysisService;
use Carbon\Carbon;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_data_links_people_employees_and_realistic_attendance(): void
    {
        Carbon::setTestNow('2026-07-27 12:00:00');

        foreach (['admin', 'teacher', 'student'] as $code) {
            Role::query()->firstOrCreate(['code' => $code], ['name' => $code]);
        }

        $this->seed(DemoDataSeeder::class);

        $this->assertSame(600, Student::query()->count());
        $this->assertSame(70, Teacher::query()->count());
        $this->assertGreaterThanOrEqual(670, Person::query()->count());
        $this->assertSame(600, Student::query()->whereNotNull('person_id')->count());
        $this->assertSame(70, Teacher::query()->whereNotNull('person_id')->count());
        $this->assertSame(70, Employee::query()->where('is_teacher', true)->count());
        $studentNames = Student::query()->get(['last_name', 'first_name', 'middle_name'])
            ->map(fn (Student $student): string => trim($student->last_name.' '.$student->first_name.' '.$student->middle_name))
            ->unique()
            ->count();
        $teacherNames = Teacher::query()->get(['last_name', 'first_name', 'middle_name'])
            ->map(fn (Teacher $teacher): string => trim($teacher->last_name.' '.$teacher->first_name.' '.$teacher->middle_name))
            ->unique()
            ->count();
        $this->assertGreaterThan(100, $studentNames);
        $this->assertGreaterThan(50, $teacherNames);

        $this->assertGreaterThan(0, AccessEvent::query()->where('result', AccessEvent::RESULT_DENIED)->count());
        $this->assertGreaterThan(0, AccessEvent::query()->where('direction', AccessEvent::DIRECTION_OUT)->count());

        $summary = app(AttendanceAnalysisService::class)->teachersToday()['summary'];
        $this->assertGreaterThan(0, $summary['late']);
        $this->assertGreaterThan(0, $summary['absent']);
        $this->assertGreaterThan(0, $summary['on_time']);

        Carbon::setTestNow();
    }
}
