<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Дата зачисления берётся из даты приказа о зачислении — решение владельца
 * от 23.08.2026. Данные вымышленные.
 */
class EnrollmentDateFromOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_takes_the_date_from_the_order(): void
    {
        $student = $this->makeStudent(['enrollment_order_date' => '2026-08-17']);

        $this->artisan('students:enrollment-date-from-order', ['--apply' => true])->assertSuccessful();

        $this->assertSame('2026-08-17', $student->refresh()->enrollment_date->toDateString());
    }

    public function test_it_keeps_a_date_that_was_already_set(): void
    {
        $student = $this->makeStudent([
            'enrollment_order_date' => '2026-08-17',
            'enrollment_date' => '2026-09-01',
        ]);

        $this->artisan('students:enrollment-date-from-order', ['--apply' => true])->assertSuccessful();

        $this->assertSame('2026-09-01', $student->refresh()->enrollment_date->toDateString());
    }

    public function test_it_leaves_a_card_without_an_order_alone(): void
    {
        $student = $this->makeStudent(['enrollment_order_date' => null]);

        $this->artisan('students:enrollment-date-from-order', ['--apply' => true])->assertSuccessful();

        $this->assertNull($student->refresh()->enrollment_date);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $student = $this->makeStudent(['enrollment_order_date' => '2026-08-17']);

        $this->artisan('students:enrollment-date-from-order')->assertSuccessful();

        $this->assertNull($student->refresh()->enrollment_date);
    }

    /** @param array<string, mixed> $extra */
    private function makeStudent(array $extra): Student
    {
        $group = Group::firstOrCreate(
            ['name' => 'Хореографическое творчество, набор 2026'],
            ['specialty' => 'Народное художественное творчество', 'course' => 1, 'year_start' => 2026],
        );

        return Student::create([
            'group_id' => $group->id,
            'last_name' => 'Ковалёва',
            'first_name' => 'Полина',
            'status' => 'active',
            'enrollment_order_number' => '114',
        ] + $extra);
    }
}
