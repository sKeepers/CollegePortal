<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\Person;
use App\Models\RfidCard;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\QrSvgService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Пропуск, у которого не осталось владельца.
 *
 * Связи у пропуска с владельцем нет: `entity_type` и `entity_id` указывают в
 * три разные таблицы, и внешнего ключа не заведено ни к одной. Каскаду
 * сработать не по чему, и пропуск переживает владельца по устройству, а не по
 * решению.
 *
 * **Удаление через приложение дверь закрывает** — `TeacherObserver::deleting` и
 * его собратья зовут `DigitalPassRevocationService`, и пропуск становится
 * отозванным. Это проверено ниже отдельно, чтобы никто не «чинил» работающее.
 *
 * Дыра там, где строка владельца исчезает **мимо Eloquent**, и такой путь в
 * портале есть прямо сейчас: у `students.group_id` стоит `ON DELETE CASCADE`
 * (проверено на стенде, `confdeltype = c`), поэтому удаление группы сносит её
 * студентов в самой базе. Наблюдатель не срабатывает, отзыва не происходит, и
 * пропуск остаётся **действующим у человека, которого больше нет**.
 */
class DigitalPassWithoutOwnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    /**
     * Каскад из базы: удалили группу — студентов не стало, пропуск остался.
     *
     * Это воспроизведение живого пути, а не выдуманного: `GroupController`
     * удаляет группу обычным `delete()`, каскад срабатывает в PostgreSQL, и
     * `StudentObserver::deleting` не зовётся вовсе. До этой проверки такой
     * пропуск открывал турникет.
     */
    public function test_a_pass_orphaned_by_a_database_cascade_does_not_open_the_gate(): void
    {
        $person = $this->person();

        $group = Group::create([
            'name' => 'ИСП-001',
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);

        $student = Student::create([
            'group_id' => $group->id,
            'person_id' => $person->id,
            'last_name' => 'Стёртов',
            'first_name' => 'Илья',
            'status' => 'active',
        ]);

        $identity = $this->passFor(DigitalIdentity::ENTITY_STUDENT, $student->id, $person->id);

        $group->delete();

        // Студента нет, а пропуск цел и по-прежнему действующий: отзывать его
        // было некому. Ровно это и делает проверку владельца необходимой.
        $this->assertDatabaseMissing('students', ['id' => $student->id]);
        $this->assertSame(DigitalIdentity::STATUS_ACTIVE, $identity->fresh()->status);

        $this->postJson('/api/access/scan', ['token' => $this->payload($identity)])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.reason', 'Владелец пропуска удалён из системы.');
    }

    /**
     * Карта того же человека — тоже отказ.
     *
     * Проход по карте идёт своим путём и мимо `scanResult`, поэтому проверка
     * нужна в двух местах. Забыть второе легко: экран проходной один, и отказ
     * по QR выглядел бы как «сделано».
     */
    public function test_a_card_of_an_erased_owner_is_refused(): void
    {
        $person = $this->person();
        $teacher = $this->teacher($person);
        $this->passFor(DigitalIdentity::ENTITY_TEACHER, $teacher->id, $person->id);

        $card = RfidCard::create([
            'uid' => '0008327739',
            'person_id' => $person->id,
            'status' => RfidCard::STATUS_ISSUED,
            'issued_at' => now(),
        ]);

        $this->eraseRow('teachers', $teacher->id);

        $this->postJson('/api/access/scan', ['token' => $card->uid])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.reason', "Владелец карты {$card->uid} удалён из системы.");
    }

    /**
     * Удаление через приложение отзывает пропуск само — и это работает.
     *
     * Проверка стоит не ради новой правки, а ради старой: путь через
     * наблюдателя закрывает дверь раньше и называет причину точнее. Причина
     * «Пропуск отозван» здесь правильнее, чем «владелец удалён», — отзыв был
     * осознанным действием, и запись о нём есть.
     */
    public function test_deleting_the_owner_through_the_app_revokes_the_pass_itself(): void
    {
        $person = $this->person();
        $teacher = $this->teacher($person);
        $identity = $this->passFor(DigitalIdentity::ENTITY_TEACHER, $teacher->id, $person->id);

        $teacher->delete();

        $this->assertSame(DigitalIdentity::STATUS_REVOKED, $identity->fresh()->status);

        $this->postJson('/api/access/scan', ['token' => $this->payload($identity)])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_DENIED)
            ->assertJsonPath('data.reason', 'Пропуск отозван.');
    }

    /**
     * Ключ `owner` отдаётся всегда, даже когда владельца нет.
     *
     * На этом держится различие «владельца нет» и «связь не запрашивали».
     * Смешавшись, они давали подпись «Преподаватель #77», и владелец портала
     * читал её как преподавателя с номером.
     */
    public function test_the_resource_says_the_owner_is_absent_instead_of_omitting_it(): void
    {
        $person = $this->person();
        $teacher = $this->teacher($person);
        $identity = $this->passFor(DigitalIdentity::ENTITY_TEACHER, $teacher->id, $person->id);

        $this->eraseRow('teachers', $teacher->id);

        $response = $this->getJson('/api/digital-identities?per_page=50')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $identity->id);

        $this->assertNotNull($row, 'Пропуск без владельца пропал из списка — он улика, а не мусор.');
        $this->assertArrayHasKey('owner', $row);
        $this->assertNull($row['owner']);
        $this->assertSame(DigitalIdentity::ENTITY_TEACHER, $row['entity_type']);
    }

    /** Живого владельца проверка не задевает. */
    public function test_a_pass_with_a_living_owner_still_opens_the_gate(): void
    {
        $person = $this->person();
        $teacher = $this->teacher($person);
        $identity = $this->passFor(DigitalIdentity::ENTITY_TEACHER, $teacher->id, $person->id);

        $this->postJson('/api/access/scan', ['token' => $this->payload($identity)])
            ->assertOk()
            ->assertJsonPath('data.result', AccessEvent::RESULT_ALLOWED);
    }

    private function person(): Person
    {
        return Person::create([
            'last_name' => 'Стёртов',
            'first_name' => 'Илья',
            'status' => 'active',
        ]);
    }

    private function teacher(Person $person): Teacher
    {
        return Teacher::create([
            'person_id' => $person->id,
            'last_name' => 'Стёртов',
            'first_name' => 'Илья',
            'department' => 'Музыкальное отделение',
        ]);
    }

    private function passFor(string $entityType, int $entityId, int $personId): DigitalIdentity
    {
        return DigitalIdentity::create([
            'person_id' => $personId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ]);
    }

    /**
     * Убрать строку владельца мимо Eloquent — так это делает каскад базы.
     *
     * Через модель наблюдатель отозвал бы пропуск, и проверять было бы нечего.
     */
    private function eraseRow(string $table, int $id): void
    {
        DB::table($table)->where('id', $id)->delete();
    }

    private function payload(DigitalIdentity $identity): string
    {
        return app(QrSvgService::class)->dynamicPayload($identity->fresh())['payload'];
    }
}
