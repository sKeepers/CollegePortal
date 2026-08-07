<?php

namespace Tests\Feature;

use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\AccountProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountPassProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_creation_issues_a_digital_pass(): void
    {
        Role::create(['name' => 'Студент', 'code' => 'student']);
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        $student = Student::create([
            'group_id' => $group->id,
            'last_name' => 'Иванов',
            'first_name' => 'Дмитрий',
            'status' => 'active',
            'phone' => '79990000001',
        ]);

        app(AccountProvisioningService::class)->provision($student);

        $identity = DigitalIdentity::query()
            ->where('entity_type', DigitalIdentity::ENTITY_STUDENT)
            ->where('entity_id', $student->id)
            ->where('status', DigitalIdentity::STATUS_ACTIVE)
            ->first();

        $this->assertNotNull($identity, 'Учетная запись создана, а пропуск не выпущен.');
        $this->assertSame($student->refresh()->person_id, $identity->person_id);
    }

    public function test_existing_active_pass_is_not_reissued(): void
    {
        Role::create(['name' => 'Преподаватель', 'code' => 'teacher']);
        $teacher = Teacher::create([
            'last_name' => 'Петров',
            'first_name' => 'Алексей',
            'is_active' => true,
            'phone' => '79990000002',
        ]);
        $existing = DigitalIdentity::create([
            'entity_type' => DigitalIdentity::ENTITY_TEACHER,
            'entity_id' => $teacher->id,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ]);

        app(AccountProvisioningService::class)->provision($teacher);

        $this->assertSame(
            $existing->token,
            DigitalIdentity::query()->whereKey($existing->id)->value('token'),
            'Действующий пропуск не должен переоформляться при создании учетной записи.'
        );
        $this->assertSame(1, DigitalIdentity::query()
            ->where('entity_type', DigitalIdentity::ENTITY_TEACHER)
            ->where('entity_id', $teacher->id)
            ->where('status', DigitalIdentity::STATUS_ACTIVE)
            ->count());
    }
}
