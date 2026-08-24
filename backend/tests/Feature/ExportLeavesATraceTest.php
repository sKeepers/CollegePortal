<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Person;
use App\Models\Student;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Выгрузка обязана оставлять след.
 *
 * Выгрузка — момент, когда данные покидают систему. Разбор 24.08.2026 показал,
 * что из семи выгрузок портала запись в журнал оставляли две: на боевом сервере
 * портал не смог бы ответить, кто унёс список студентов со СНИЛС и паспортными
 * данными, и не смог бы никогда — такую запись нельзя восстановить задним
 * числом.
 *
 * Проверяется здесь и обратное: в журнал попадает **счёт, а не данные**. Запись
 * о выгрузке паспортов, содержащая паспорта, завела бы вторую копию рядом с
 * первой.
 */
class ExportLeavesATraceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_a_student_export_records_who_took_what(): void
    {
        $this->students();
        $user = $this->createApiUser(roleCode: 'admin');

        $response = $this->withApiAuth($user)->get('/api/students/export');
        $response->assertOk();
        // Поток выполняется только когда его читают: без этого тело выгрузки
        // не запускается вовсе, и след писать нечему.
        $response->streamedContent();

        $log = AuditLog::query()->where('action', 'csv_exported')->latest('id')->first();

        $this->assertNotNull($log, 'Выгрузка студентов не оставила следа в журнале.');
        $this->assertSame($user->id, $log->user_id, 'В следе не записано, кто выгружал.');
        $this->assertSame('api/students/export', $log->new_values['path'] ?? null);
        $this->assertSame(2, $log->new_values['rows'] ?? null, 'В следе должно стоять число выгруженных строк.');
    }

    public function test_the_trace_keeps_the_filter_but_not_the_search_text(): void
    {
        $this->students();

        $response = $this->withApiAuth($this->createApiUser(roleCode: 'admin'))
            ->get('/api/students/export?status=active&search=Иванов');
        $response->assertOk();
        $response->streamedContent();

        $log = AuditLog::query()->where('action', 'csv_exported')->latest('id')->firstOrFail();
        $filters = $log->new_values['filters'] ?? [];

        $this->assertSame('active', $filters['status'] ?? null, 'Отбор обязан остаться: без него видно число, но не видно, кого унесли.');
        $this->assertSame('[задан]', $filters['search'] ?? null, 'Строка поиска в журнал не пишется: по ней туда попала бы фамилия.');
        $this->assertStringNotContainsString('Иванов', json_encode($log->new_values, JSON_UNESCAPED_UNICODE));
    }

    private function students(): void
    {
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Искусство', 'course' => 1, 'year_start' => 2026]);

        foreach ([['Иванов', 'Иван'], ['Петрова', 'Мария']] as [$lastName, $firstName]) {
            $person = Person::create(['last_name' => $lastName, 'first_name' => $firstName, 'status' => 'active']);
            Student::create([
                'person_id' => $person->id,
                'last_name' => $lastName,
                'first_name' => $firstName,
                'group_id' => $group->id,
                'status' => 'active',
            ]);
        }
    }
}
