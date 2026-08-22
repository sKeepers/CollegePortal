<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Курс группы считается из года набора, а не хранится.
 *
 * Данные вымышленные. Даты в тесте закреплены `Carbon::setTestNow`: без этого
 * тест перестал бы значить что-либо ровно первого августа.
 */
class GroupCourseIsCountedTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_column_is_gone(): void
    {
        $this->assertFalse(Schema::hasColumn('groups', 'course'));
    }

    public function test_the_course_follows_the_intake_year(): void
    {
        Carbon::setTestNow('2026-08-23');

        $this->assertSame(4, $this->makeGroup(2023, 'а')->course);
        $this->assertSame(3, $this->makeGroup(2024, 'б')->course);
        $this->assertSame(2, $this->makeGroup(2025, 'в')->course);
        $this->assertSame(1, $this->makeGroup(2026, 'г')->course);
    }

    /**
     * Учебный год считается с августа, а не с сентября: группы нового набора
     * колледж заводит в августе, и с этого момента прошлые уже перешли.
     */
    public function test_the_year_turns_over_in_august(): void
    {
        $group = $this->makeGroup(2025, 'а');

        Carbon::setTestNow('2026-07-31');
        $this->assertSame(1, $group->refresh()->course);

        Carbon::setTestNow('2026-08-01');
        $this->assertSame(2, $group->refresh()->course);
    }

    /** Ни одна группа не остаётся на нулевом курсе: набор заводят заранее. */
    public function test_a_group_of_a_future_intake_shows_the_first_course(): void
    {
        Carbon::setTestNow('2026-08-23');

        $this->assertSame(1, $this->makeGroup(2027, 'а')->course);
    }

    public function test_students_are_filtered_by_the_counted_course(): void
    {
        Carbon::setTestNow('2026-08-23');

        $fourth = $this->makeGroup(2023, 'а');
        $first = $this->makeGroup(2026, 'б');
        $this->makeStudent($fourth, 'Ковалёва');
        $this->makeStudent($first, 'Никитин');

        $response = $this->withApiAuth($this->operator())->getJson('/api/students?course=4');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Ковалёва', $response->json('data.0.last_name'));
    }

    private function makeGroup(int $yearStart, string $suffix): Group
    {
        return Group::create([
            'name' => 'Хореографическое творчество '.$suffix.', набор '.$yearStart,
            'specialty' => 'Народное художественное творчество',
            'year_start' => $yearStart,
        ]);
    }

    private function makeStudent(Group $group, string $lastName): Student
    {
        return Student::create([
            'group_id' => $group->id,
            'last_name' => $lastName,
            'first_name' => 'Полина',
            'status' => 'active',
        ]);
    }

    private function operator(): User
    {
        $role = Role::firstOrCreate(['code' => 'study_records'], ['name' => 'Учебная часть']);
        $role->permissions()->sync([Permission::query()->firstOrCreate(
            ['code' => 'students.view'],
            ['name' => 'Просмотр студентов', 'module' => 'Students', 'system' => true, 'active' => true],
        )->id]);

        return User::factory()->create(['role_id' => $role->id, 'password' => Hash::make('password')]);
    }
}
