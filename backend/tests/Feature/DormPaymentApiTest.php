<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\DormPayment;
use App\Models\DormPlacement;
use App\Models\DormRoom;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\DormPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Оплата проживания.
 *
 * Главное здесь — правило спора двух источников: строка из 1С побеждает ручную
 * отметку, а ручная помечается замещённой и остаётся. Без этого первый же
 * импорт молча сотрёт работу коменданта.
 */
class DormPaymentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_1c_row_supersedes_the_manual_note_but_keeps_it(): void
    {
        $student = $this->resident();
        $payments = app(DormPaymentService::class);

        $manual = $payments->record($student, '2026-09-30', 4500.00);
        $fromExchange = $payments->record($student, '2026-09-30', 4500.00, null, DormPayment::ORIGIN_1C, 'S-1');

        // Работа коменданта никуда не делась — её видно и видно, чем заменили.
        $this->assertSame($fromExchange->id, $manual->fresh()->superseded_by_id);
        $this->assertNotNull(DormPayment::query()->find($manual->id));
        $this->assertNull($fromExchange->fresh()->superseded_by_id);
    }

    public function test_a_1c_row_also_covers_shorter_manual_notes(): void
    {
        $student = $this->resident();
        $payments = app(DormPaymentService::class);

        $earlier = $payments->record($student, '2026-09-15');
        $later = $payments->record($student, '2026-10-31', null, null, DormPayment::ORIGIN_1C, 'S-2');

        // «Оплачено по 31 октября» перекрывает «по 15 сентября»: та отметка
        // больше ничего не добавляет.
        $this->assertSame($later->id, $earlier->fresh()->superseded_by_id);
    }

    public function test_a_manual_note_never_supersedes_the_exchange(): void
    {
        $student = $this->resident();
        $payments = app(DormPaymentService::class);

        $fromExchange = $payments->record($student, '2026-09-30', null, null, DormPayment::ORIGIN_1C, 'S-3');
        $payments->record($student, '2026-10-31');

        // Обмен — источник денег. Комендант отмечает по бумажке, пока обмена
        // нет, и перебить им 1С нельзя.
        $this->assertNull($fromExchange->fresh()->superseded_by_id);
    }

    public function test_the_effective_date_ignores_superseded_notes(): void
    {
        $student = $this->resident();
        $payments = app(DormPaymentService::class);

        $payments->record($student, '2026-12-31');
        $payments->record($student, '2026-09-30', null, null, DormPayment::ORIGIN_1C, 'S-4');

        // Ручная «по 31 декабря» пережила: она дальше срока строки из 1С, и
        // замещать её нечем. Закрыт человек по декабрь.
        $this->assertSame('2026-12-31', $payments->paidThrough($student->id)?->toDateString());
    }

    public function test_the_summary_counts_overdue_days_and_never_paid(): void
    {
        $debtor = $this->resident('Должников');
        $payer = $this->resident('Платёжников', '102');
        $this->resident('Нулевой', '103');

        $payments = app(DormPaymentService::class);
        $payments->record($debtor, '2026-08-01');
        $payments->record($payer, '2026-12-31');

        $summary = $payments->summary('2026-09-01');
        $byName = $summary->keyBy('full_name');

        $this->assertSame(31, $byName['Должников Иван']['overdue_days']);
        $this->assertSame(0, $byName['Платёжников Иван']['overdue_days']);
        $this->assertTrue($byName['Нулевой Иван']['never_paid']);
        // Просрочившие идут первыми: с ними и работают.
        $this->assertSame('Должников Иван', $summary->first()['full_name']);
    }

    public function test_the_screen_records_only_manual_notes(): void
    {
        $student = $this->resident();
        $this->withApiAuth($this->warden());

        $this->postJson('/api/dorm/payments', [
            'student_id' => $student->id,
            'paid_through' => '2026-09-30',
            'amount' => 4500,
        ])
            ->assertCreated()
            ->assertJsonPath('data.origin', DormPayment::ORIGIN_MANUAL)
            ->assertJsonPath('data.is_superseded', false);
    }

    public function test_the_deputy_does_not_see_payments_at_all(): void
    {
        // Оплата — работа коменданта. Заместителю её не дают, и это не
        // недоделка, а разграничение.
        $this->withApiAuth($this->userWith(['dorm.absences.view']));

        $this->getJson('/api/dorm/payments/summary')->assertForbidden();
        $this->postJson('/api/dorm/payments', ['student_id' => 1, 'paid_through' => '2026-09-30'])->assertForbidden();
    }

    private function resident(string $lastName = 'Проживающий', string $room = '101'): Student
    {
        $building = Building::query()->firstWhere('code', 'SER277') ?? Building::create([
            'name' => 'Общежитие на Серова, 277',
            'code' => 'SER277',
            'is_active' => true,
        ]);

        $dormRoom = DormRoom::query()->firstWhere('number', $room) ?? DormRoom::create([
            'building_id' => $building->id,
            'number' => $room,
            'capacity' => 4,
            'is_active' => true,
        ]);

        $group = Group::query()->firstWhere('name', 'ИСП-101') ?? Group::create([
            'name' => 'ИСП-101',
            'specialty' => 'Инструментальное исполнительство',
            'year_start' => 2026,
        ]);

        $student = Student::create([
            'group_id' => $group->id,
            'last_name' => $lastName,
            'first_name' => 'Иван',
            'status' => 'active',
            'is_resident' => true,
        ]);

        DormPlacement::create([
            'dorm_room_id' => $dormRoom->id,
            'student_id' => $student->id,
            'moved_in_at' => '2026-09-01',
        ]);

        return $student;
    }

    private function warden(): User
    {
        return $this->userWith(['dorm.payments.view', 'dorm.payments.manage']);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(
            ['code' => 'pay_'.substr(md5(json_encode($permissions)), 0, 12)],
            ['name' => 'Оплата '.substr(md5(json_encode($permissions)), 0, 8), 'description' => 'Test role'],
        );

        foreach ($permissions as $code) {
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'module' => 'Test', 'description' => $code, 'system' => true, 'active' => true],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->attach($role->id);

        return $user;
    }
}
