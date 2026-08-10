<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessGateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_scan_active_qr_allows_entry_and_next_scan_exit_after_interval(): void
    {
        $identity = $this->createStudentIdentity();
        $qr = app(\App\Services\QrSvgService::class);

        $this->postJson('/api/access/scan', [
            'token' => $qr->dynamicPayload($identity)['payload'],
            'access_point' => 'Главный вход',
            'device_name' => 'USB QR Scanner',
        ])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED)
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_IN)
            ->assertJsonPath('data.owner.last_name', 'Иванов');

        $this->travel(31)->seconds();

        $this->postJson('/api/access/scan', ['token' => $qr->dynamicPayload($identity)['payload']])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED)
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_OUT);
    }

    public function test_dynamic_qr_can_only_be_scanned_once(): void
    {
        $identity = $this->createStudentIdentity();
        $payload = app(\App\Services\QrSvgService::class)->dynamicPayload($identity)['payload'];

        $this->postJson('/api/access/scan', ['token' => $payload])
            ->assertOk()
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_IN)
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED);

        $this->postJson('/api/access/scan', ['token' => $payload])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.reason', 'QR-код уже использован. Покажите обновленный код.');

        $this->assertSame(2, AccessEvent::count());
    }

    /**
     * Отказ не переставляет направление.
     *
     * Камера на проходной читает телефон в кадре несколько раз подряд, а
     * динамический QR одноразовый: второй скан того же кода даёт отказ
     * «QR-код уже использован». Пока отказы участвовали в чередовании, вошедший
     * человек при следующем настоящем проходе снова «входил», хотя выходил, —
     * и это видел не только журнал, но и экран «Кто сейчас в здании».
     */
    public function test_denied_scan_does_not_flip_the_direction(): void
    {
        $identity = $this->createStudentIdentity();
        $qr = app(\App\Services\QrSvgService::class);
        $payload = $qr->dynamicPayload($identity)['payload'];

        $this->postJson('/api/access/scan', ['token' => $payload])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED)
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_IN);

        // Тот же код камера прочитала повторно: проход не состоялся.
        $this->postJson('/api/access/scan', ['token' => $payload])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED);

        $this->travel(31)->seconds();

        $this->postJson('/api/access/scan', ['token' => $qr->dynamicPayload($identity)['payload']])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED)
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_OUT);
    }

    public function test_scan_revoked_qr_creates_denied_event(): void
    {
        $identity = $this->createTeacherIdentity([
            'status' => DigitalIdentity::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        $this->postJson('/api/access/scan', ['token' => app(\App\Services\QrSvgService::class)->dynamicPayload($identity)['payload']])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.reason', 'Пропуск отозван.')
            ->assertJsonPath('data.owner.last_name', 'Смирнова');
    }

    public function test_scan_unknown_token_creates_denied_event_without_owner(): void
    {
        $this->postJson('/api/access/scan', ['token' => 'unknown-token'])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.reason', 'Пропуск не найден.')
            ->assertJsonPath('data.owner', null);

        $this->assertDatabaseHas('access_events', [
            'digital_identity_id' => null,
            'result' => AccessEvent::RESULT_DENIED,
            'reason' => 'Пропуск не найден.',
        ]);
    }

    public function test_it_lists_access_events(): void
    {
        $identity = $this->createStudentIdentity();
        $this->postJson('/api/access/scan', ['token' => app(\App\Services\QrSvgService::class)->dynamicPayload($identity)['payload']])->assertOk();

        $this->getJson('/api/access/events')
            ->assertOk()
            ->assertJsonPath('data.0.result', AccessEvent::RESULT_ALLOWED)
            ->assertJsonPath('data.0.owner.last_name', 'Иванов');
    }



    public function test_static_and_cp1_qr_payloads_are_rejected(): void
    {
        $identity = $this->createStudentIdentity();

        $this->postJson('/api/access/scan', ['token' => " CP1:{$identity->token}\r\n"])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.owner', null);
    }

    public function test_scan_accepts_short_lived_cp2_payload(): void
    {
        $identity = $this->createStudentIdentity();
        $payload = app(\App\Services\QrSvgService::class)->dynamicPayload($identity)['payload'];

        $this->assertStringStartsWith('CP2:', $payload);
        $this->assertLessThanOrEqual(32, strlen($payload));
        $this->assertStringNotContainsString($identity->token, $payload);

        $this->postJson('/api/access/scan', ['token' => $payload])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED)
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_IN)
            ->assertJsonPath('data.owner.last_name', 'Иванов');
    }

    public function test_expired_cp2_payload_is_denied_without_identity_lookup(): void
    {
        $identity = $this->createStudentIdentity();
        $payload = app(\App\Services\QrSvgService::class)->dynamicPayload($identity, 5)['payload'];

        $this->travel(6)->seconds();

        $this->postJson('/api/access/scan', ['token' => $payload])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.reason', 'Пропуск не найден.')
            ->assertJsonPath('data.owner', null);
    }

    public function test_scan_accepts_cp2_payload_typed_with_russian_keyboard_layout(): void
    {
        $identity = $this->createStudentIdentity();
        $payload = app(\App\Services\QrSvgService::class)->dynamicPayload($identity)['payload'];
        $typedInRussian = $this->asRussianLayout($payload);

        $this->assertNotSame($payload, $typedInRussian);

        $this->postJson('/api/access/scan', ['token' => $typedInRussian])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED)
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_IN)
            ->assertJsonPath('data.owner.last_name', 'Иванов');
    }

    public function test_russian_layout_normalization_does_not_allow_unknown_tokens(): void
    {
        $this->createStudentIdentity();

        $this->postJson('/api/access/scan', ['token' => 'случайный текст'])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.reason', 'Пропуск не найден.')
            ->assertJsonPath('data.owner', null);
    }

    public function test_russian_layout_normalization_does_not_revive_static_token(): void
    {
        $identity = $this->createStudentIdentity();

        $this->postJson('/api/access/scan', ['token' => $this->asRussianLayout($identity->token)])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.owner', null);
    }

    private function asRussianLayout(string $value): string
    {
        $map = [
            'q' => 'й', 'w' => 'ц', 'e' => 'у', 'r' => 'к', 't' => 'е', 'y' => 'н', 'u' => 'г', 'i' => 'ш', 'o' => 'щ', 'p' => 'з',
            'a' => 'ф', 's' => 'ы', 'd' => 'в', 'f' => 'а', 'g' => 'п', 'h' => 'р', 'j' => 'о', 'k' => 'л', 'l' => 'д',
            'z' => 'я', 'x' => 'ч', 'c' => 'с', 'v' => 'м', 'b' => 'и', 'n' => 'т', 'm' => 'ь',
            'Q' => 'Й', 'W' => 'Ц', 'E' => 'У', 'R' => 'К', 'T' => 'Е', 'Y' => 'Н', 'U' => 'Г', 'I' => 'Ш', 'O' => 'Щ', 'P' => 'З',
            'A' => 'Ф', 'S' => 'Ы', 'D' => 'В', 'F' => 'А', 'G' => 'П', 'H' => 'Р', 'J' => 'О', 'K' => 'Л', 'L' => 'Д',
            'Z' => 'Я', 'X' => 'Ч', 'C' => 'С', 'V' => 'М', 'B' => 'И', 'N' => 'Т', 'M' => 'Ь',
            ':' => 'Ж',
        ];

        return strtr($value, $map);
    }



    public function test_access_scan_permissions_allow_security_and_block_teacher_student(): void
    {
        $identity = $this->createStudentIdentity();
        $permission = Permission::query()->firstOrCreate(['code' => 'gate.scan'], ['name' => 'Проходная: сканирование']);
        Role::query()->firstOrCreate(['code' => 'security'], ['name' => 'Сотрудник проходной'])->permissions()->sync([$permission->id]);
        $security = $this->createApiUser(roleCode: 'security');
        $teacher = $this->createApiUser(roleCode: 'teacher');
        $student = $this->createApiUser(roleCode: 'student');

        $this->withApiAuth($security)
            ->postJson('/api/access/scan', ['token' => app(\App\Services\QrSvgService::class)->dynamicPayload($identity)['payload']])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED);

        $this->withApiAuth($teacher)
            ->postJson('/api/access/scan', ['token' => $identity->token])
            ->assertForbidden();

        $this->withApiAuth($student)
            ->postJson('/api/access/scan', ['token' => $identity->token])
            ->assertForbidden();
    }

    private function createStudentIdentity(array $overrides = []): DigitalIdentity
    {
        $group = Group::create([
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

        return DigitalIdentity::create(array_merge([
            'entity_type' => DigitalIdentity::ENTITY_STUDENT,
            'entity_id' => $student->id,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ], $overrides));
    }

    private function createTeacherIdentity(array $overrides = []): DigitalIdentity
    {
        $teacher = Teacher::create([
            'last_name' => 'Смирнова',
            'first_name' => 'Елена',
            'department' => 'Музыкальное отделение',
        ]);

        return DigitalIdentity::create(array_merge([
            'entity_type' => DigitalIdentity::ENTITY_TEACHER,
            'entity_id' => $teacher->id,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ], $overrides));
    }
}
