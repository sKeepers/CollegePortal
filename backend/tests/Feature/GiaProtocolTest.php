<?php

namespace Tests\Feature;

use App\Models\GiaProtocol;
use App\Models\GiaProtocolDecision;
use App\Models\Group;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Протокол ГИА.
 *
 * До 24.08.2026 государственная итоговая аттестация была строкой экзамена с типом `gia`,
 * а решение о присвоении квалификации — свободным полем в дипломе. Приказ о выпуске и
 * выгрузка в ФРДО опираются ровно на то, чего не было: номер и дата протокола,
 * председатель комиссии, решение по каждому выпускнику.
 */
class GiaProtocolTest extends TestCase
{
    use RefreshDatabase;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->group = Group::create([
            'name' => 'Фортепиано, набор 2023',
            'specialty' => '53.02.03 Инструментальное исполнительство',
            'year_start' => 2023,
        ]);
    }

    /** Без председателя протокол недействителен — его требуют и приказ, и ФРДО. */
    public function test_a_protocol_without_a_chairman_is_refused(): void
    {
        $payload = $this->protocolPayload();
        unset($payload['chairman']);

        $response = $this->withApiAuth($this->officeUser())
            ->postJson('/api/gia-protocols', $payload)
            ->assertStatus(422);

        $this->assertStringContainsString('председател', mb_strtolower((string) $response->json('message')));
    }

    /** Название группы записывается в сам протокол: документ обязан читаться и без неё. */
    public function test_the_group_name_is_written_into_the_protocol(): void
    {
        $this->withApiAuth($this->officeUser())
            ->postJson('/api/gia-protocols', $this->protocolPayload())
            ->assertCreated();

        $this->assertSame('Фортепиано, набор 2023', GiaProtocol::query()->value('group_name'));
    }

    /** Ведомость строится от состава группы, включая тех, по кому решения ещё нет. */
    public function test_the_sheet_lists_every_student_of_the_group(): void
    {
        $this->students(3);
        $protocol = $this->protocol();

        $rows = $this->withApiAuth($this->officeUser())
            ->getJson("/api/gia-protocols/{$protocol->id}/decisions")
            ->assertOk()
            ->json('data');

        $this->assertCount(3, $rows);
        $this->assertNull($rows[0]['result']);
        $this->assertTrue($rows[0]['in_group']);
    }

    public function test_decisions_are_saved_and_replaced(): void
    {
        [$student] = $this->students(1);
        $protocol = $this->protocol();
        $user = $this->officeUser();

        $this->withApiAuth($user)->postJson("/api/gia-protocols/{$protocol->id}/decisions", [
            'decisions' => [['student_id' => $student->id, 'result' => 'passed', 'mark' => '5']],
        ])->assertOk()->assertJsonPath('data.saved', 1);

        $this->withApiAuth($user)->postJson("/api/gia-protocols/{$protocol->id}/decisions", [
            'decisions' => [['student_id' => $student->id, 'result' => 'failed', 'note' => 'не явился на защиту']],
        ])->assertOk();

        $this->assertDatabaseCount('gia_protocol_decisions', 1);
        $decision = GiaProtocolDecision::query()->firstOrFail();
        $this->assertSame('failed', $decision->result);
        $this->assertNotEmpty($decision->student_name);
    }

    /** Внесли не тому — должно быть чем убрать. */
    public function test_an_empty_result_removes_the_decision(): void
    {
        [$student] = $this->students(1);
        $protocol = $this->protocol();
        $user = $this->officeUser();

        $this->withApiAuth($user)->postJson("/api/gia-protocols/{$protocol->id}/decisions", [
            'decisions' => [['student_id' => $student->id, 'result' => 'passed']],
        ])->assertOk();

        $this->withApiAuth($user)->postJson("/api/gia-protocols/{$protocol->id}/decisions", [
            'decisions' => [['student_id' => $student->id, 'result' => '']],
        ])->assertOk()->assertJsonPath('data.removed', 1);

        $this->assertDatabaseCount('gia_protocol_decisions', 0);
    }

    /**
     * Утверждённый протокол не правится: на него ссылается диплом, а диплом выдан на
     * руки. Отказ обязан назвать причину.
     */
    public function test_an_approved_protocol_is_not_edited(): void
    {
        [$student] = $this->students(1);
        $protocol = $this->protocol();
        $protocol->forceFill(['status' => 'approved'])->save();

        $response = $this->withApiAuth($this->officeUser())
            ->postJson("/api/gia-protocols/{$protocol->id}/decisions", [
                'decisions' => [['student_id' => $student->id, 'result' => 'passed']],
            ])
            ->assertStatus(409);

        $this->assertStringContainsString('утверждён', (string) $response->json('message'));
    }

    /**
     * Студент ушёл из группы после заседания — строка остаётся видимой. Протокол
     * подписан, и вычёркивать из него нельзя.
     */
    public function test_a_decision_survives_the_student_leaving_the_group(): void
    {
        [$student] = $this->students(1);
        $protocol = $this->protocol();
        $user = $this->officeUser();

        $this->withApiAuth($user)->postJson("/api/gia-protocols/{$protocol->id}/decisions", [
            'decisions' => [['student_id' => $student->id, 'result' => 'passed']],
        ])->assertOk();

        $other = Group::create(['name' => 'Теория музыки, набор 2023', 'specialty' => '53.02.07 Теория музыки', 'year_start' => 2023]);
        $student->forceFill(['group_id' => $other->id])->save();

        $rows = $this->withApiAuth($user)
            ->getJson("/api/gia-protocols/{$protocol->id}/decisions")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('passed', $rows[0]['result']);
        $this->assertFalse($rows[0]['in_group']);
    }

    /** @return array<int, Student> */
    private function students(int $count): array
    {
        $students = [];

        for ($i = 1; $i <= $count; $i++) {
            $students[] = Student::create([
                'group_id' => $this->group->id,
                'last_name' => 'Выпускник'.$i,
                'first_name' => 'Имя'.$i,
                'status' => 'active',
            ]);
        }

        return $students;
    }

    private function protocol(): GiaProtocol
    {
        $this->withApiAuth($this->officeUser())
            ->postJson('/api/gia-protocols', $this->protocolPayload())
            ->assertCreated();

        return GiaProtocol::query()->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function protocolPayload(): array
    {
        return [
            'number' => '1',
            'protocol_date' => '2027-06-25',
            'academic_year' => '2026/2027',
            'group_id' => $this->group->id,
            'chairman' => 'Рябцев Сергей Иванович',
            'chairman_position' => 'директор филармонии',
            'secretary' => 'Дегтева Анна Петровна',
            'members' => [
                ['name' => 'Власова Ирина Николаевна', 'position' => 'преподаватель'],
                ['name' => 'Горбачева Татьяна Ивановна', 'position' => 'преподаватель'],
            ],
        ];
    }

    private function officeUser(): User
    {
        return $this->createApiUser(null, 'study_records');
    }
}
