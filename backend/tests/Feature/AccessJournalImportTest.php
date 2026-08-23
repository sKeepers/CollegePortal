<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\AccessPoint;
use App\Models\AccessPointDevice;
use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\Person;
use App\Models\RfidCard;
use App\Models\Student;
use App\Services\AccessJournalImportService;
use App\Support\Access\CarddexCsvJournal;
use App\Support\Access\JournalRow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Загрузка журнала проходов из чужой СКУД.
 *
 * Каждая проверка здесь стоит за числом, снятым на копии действующей системы:
 * 50 337 событий, 16 793 прохода, 707 карт, два года. Репетиция описана в
 * `docs/CARDDEX_IMPORT_REHEARSAL.md`.
 */
class AccessJournalImportTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = CarddexCsvJournal::SOURCE;

    /**
     * Повторная выборка не создаёт второго прохода.
     *
     * Связь с контроллером рвётся, отрезки времени в запросах перекрываются, и
     * одни и те же события приезжают снова — это норма работы, а не сбой. На
     * копии действующей СКУД повтор без этого ключа дал 33 582 события вместо
     * 16 791: журнал ровно удвоился, а следом удвоились бы присутствие,
     * опоздания и ночные отсутствия в общежитии.
     */
    public function test_a_repeated_fetch_does_not_double_the_journal(): void
    {
        $this->mapDevice('3', AccessEvent::DIRECTION_IN);

        $rows = [
            $this->row('101', '2026-03-02 08:14:00', '3', '0008327739'),
            $this->row('102', '2026-03-02 17:40:00', '3', '0008327739'),
        ];

        $first = $this->service()->import(self::SOURCE, $rows);
        $second = $this->service()->import(self::SOURCE, $rows);

        $this->assertSame(2, $first->imported);
        $this->assertSame(0, $second->imported);
        $this->assertSame(2, $second->alreadyPresent);
        $this->assertSame(2, AccessEvent::query()->count());
    }

    /**
     * Направление берётся у устройства и не пересчитывается чередованием.
     *
     * Это не тонкость, а половина журнала. Замер на 16 436 разрешённых проходах
     * копии: чередование разошлось с устройством 8 063 раза, в 49,1 % случаев,
     * у 668 карт из 707. Причина видна там же — 6 985 раз человек проходил
     * подряд в одну и ту же сторону, и 6 372 из них это два входа подряд: на
     * выход турникет пускают свободно, картой отмечаются не все. Чередование в
     * таком журнале не «слегка ошибается», оно выдумывает выходы, которых не
     * было.
     */
    public function test_two_entries_in_a_row_stay_two_entries(): void
    {
        $this->mapDevice('3', AccessEvent::DIRECTION_IN);

        $this->service()->import(self::SOURCE, [
            $this->row('201', '2026-03-02 08:14:00', '3', '0008327739'),
            $this->row('202', '2026-03-02 12:05:00', '3', '0008327739'),
        ]);

        $directions = AccessEvent::query()->orderBy('event_time')->pluck('direction')->all();

        $this->assertSame([AccessEvent::DIRECTION_IN, AccessEvent::DIRECTION_IN], $directions);
    }

    /**
     * Устройства нет в справочнике — событие называется, а не угадывается.
     *
     * Направление наугад врёт в присутствии, опозданиях и ночных отсутствиях, и
     * врёт молча. Непринятая строка видна в отчёте, а внешний идентификатор
     * делает её загрузку после пополнения справочника безопасной.
     */
    public function test_an_unknown_device_is_named_and_not_guessed(): void
    {
        $this->mapDevice('3', AccessEvent::DIRECTION_IN);

        $report = $this->service()->import(self::SOURCE, [
            $this->row('301', '2026-03-02 08:14:00', '3', '0008327739'),
            $this->row('302', '2026-03-02 08:15:00', '9', '0008327739'),
            $this->row('303', '2026-03-02 08:16:00', null, '0008327739'),
        ]);

        $this->assertSame(1, $report->imported);
        $this->assertSame(2, $report->skippedUnknownDevice);
        $this->assertSame(1, $report->unknownDevices['9']);
        $this->assertSame(1, $report->unknownDevices['(нет устройства)']);
        $this->assertTrue($report->matches());
    }

    /** Справочник пополнили — пропущенное догружается, и без второй копии. */
    public function test_a_skipped_pass_arrives_after_the_directory_is_filled(): void
    {
        $this->mapDevice('3', AccessEvent::DIRECTION_IN);

        $rows = [
            $this->row('401', '2026-03-02 08:14:00', '3', '0008327739'),
            $this->row('402', '2026-03-02 17:40:00', '2', '0008327739'),
        ];

        $this->assertSame(1, $this->service()->import(self::SOURCE, $rows)->imported);

        $this->mapDevice('2', AccessEvent::DIRECTION_OUT);

        $second = $this->service()->import(self::SOURCE, $rows);

        $this->assertSame(1, $second->imported);
        $this->assertSame(1, $second->alreadyPresent);
        $this->assertSame(2, AccessEvent::query()->count());
    }

    /** Проход по заведённой карте достаётся её хозяину. */
    public function test_an_imported_pass_finds_its_owner_by_card(): void
    {
        $this->mapDevice('3', AccessEvent::DIRECTION_IN);
        ['identity' => $identity, 'student' => $student] = $this->personWithCard('0008327739');

        $this->service()->import(self::SOURCE, [
            $this->row('501', '2026-03-02 08:14:00', '3', '0008327739'),
        ]);

        $event = AccessEvent::query()->firstOrFail();

        $this->assertSame($identity->id, $event->digital_identity_id);
        $this->assertSame(DigitalIdentity::ENTITY_STUDENT, $event->entity_type);
        $this->assertSame($student->id, $event->entity_id);
    }

    /**
     * Карты в портале нет — проход всё равно записан, и номер при нём остался.
     *
     * На копии действующей СКУД так выглядят все 16 791 событие: карт там 707,
     * а в портале не заведена ни одна. Без номера при событии привязать их к
     * людям потом было бы уже не из чего.
     */
    public function test_a_pass_of_an_unregistered_card_keeps_the_number(): void
    {
        $this->mapDevice('3', AccessEvent::DIRECTION_IN);

        $report = $this->service()->import(self::SOURCE, [
            $this->row('601', '2026-03-02 08:14:00', '3', '0008327739'),
        ]);

        $event = AccessEvent::query()->firstOrFail();

        $this->assertSame(1, $report->unresolvedCard);
        $this->assertNull($event->digital_identity_id);
        $this->assertSame('0008327739', $event->card_uid);
        $this->assertSame(AccessEvent::RESULT_ALLOWED, $event->result);
    }

    /**
     * Один проход, записанный контроллером дважды.
     *
     * В копии таких пар 228: та же карта, то же устройство, та же доля секунды,
     * но разные идентификаторы событий — то есть внешний ключ их дублями не
     * считает и считать не должен. По умолчанию они грузятся: журнал обязан
     * отражать источник, а свернуть их можно и потом, идентификатор при них
     * остался. Обратное — потеря прохода, и потеря молчаливая.
     */
    public function test_a_pass_written_twice_by_the_controller_is_counted_and_can_be_collapsed(): void
    {
        $this->mapDevice('3', AccessEvent::DIRECTION_IN);

        $rows = [
            $this->row('701', '2026-03-02 08:14:00', '3', '0008327739'),
            $this->row('702', '2026-03-02 08:14:00', '3', '0008327739'),
        ];

        $kept = $this->service()->import(self::SOURCE, $rows);

        $this->assertSame(1, $kept->sourceDoubles);
        $this->assertSame(0, $kept->collapsed);
        $this->assertSame(2, $kept->imported);

        AccessEvent::query()->delete();

        $collapsed = $this->service()->import(self::SOURCE, $rows, collapseSourceDoubles: true);

        $this->assertSame(1, $collapsed->collapsed);
        $this->assertSame(1, $collapsed->imported);
        $this->assertTrue($collapsed->matches());
    }

    /**
     * Разбор выгрузки принимает три типа события из сорока с лишним.
     *
     * Открытие и закрытие створки турникета (53 и 54) идут почти парой к
     * каждому разрешённому проходу: прими их за проходы — и журнал вырастет
     * втрое, а присутствие перевернётся на каждом человеке. В копии их 30 450
     * при 16 793 настоящих проходах.
     */
    public function test_the_reader_takes_passes_and_leaves_the_turnstile_mechanics(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cdx');

        file_put_contents($file, implode("\n", [
            'external_id;event_type;device;event_time;card',
            '1;50;3;2026-03-02 08:14:00.0000;0008327739',
            '2;53;3;2026-03-02 08:14:01.0000;',
            '3;54;3;2026-03-02 08:14:05.0000;',
            '4;46;3;2026-03-02 08:20:00.0000;0009999999',
            '5;13;;2026-03-02 09:00:00.0000;',
        ])."\n");

        try {
            $rows = iterator_to_array(CarddexCsvJournal::rows($file), false);
        } finally {
            @unlink($file);
        }

        $this->assertCount(2, $rows);
        $this->assertSame(AccessEvent::RESULT_ALLOWED, $rows[0]->result);
        $this->assertSame(AccessEvent::RESULT_DENIED, $rows[1]->result);
        $this->assertStringContainsString('0009999999', (string) $rows[1]->reason);
        $this->assertSame('0008327739', $rows[0]->cardUid);
    }

    /**
     * Живое сканирование и загруженный журнал — одна цепочка, а не две.
     *
     * Загрузка ставит человеку последний проход, и следующий скан чередуется от
     * него. Это то, что нужно, но за это надо знать цену: направление живого
     * скана зависит от того, успела ли пройти загрузка. Отстала выборка на час
     * — и человек, вошедший через турникет, при сканировании QR войдёт второй
     * раз. Лечится не здесь, а частотой выборки; проверка стоит затем, чтобы
     * связь между ними была видна, а не открывалась потом в журнале.
     */
    public function test_an_imported_pass_sets_the_direction_of_the_next_live_scan(): void
    {
        $this->withApiAuth();
        $this->mapDevice('3', AccessEvent::DIRECTION_IN);
        ['card' => $card] = $this->personWithCard('0008327739');

        $this->service()->import(self::SOURCE, [
            $this->row('801', CarbonImmutable::now()->subMinutes(10)->format('Y-m-d H:i:s'), '3', $card->uid),
        ]);

        $this->postJson('/api/access/scan', ['token' => $card->uid])
            ->assertOk()
            ->assertJsonPath('data.direction', AccessEvent::DIRECTION_OUT);
    }

    /** Живой проход по карте тоже помнит её номер. */
    public function test_a_live_card_scan_keeps_the_card_number(): void
    {
        $this->withApiAuth();
        ['card' => $card] = $this->personWithCard('0008327739');

        $this->postJson('/api/access/scan', ['token' => $card->uid])->assertOk();

        $this->assertSame($card->uid, AccessEvent::query()->latest('id')->value('card_uid'));
    }

    private function service(): AccessJournalImportService
    {
        return app(AccessJournalImportService::class);
    }

    private function row(string $id, string $time, ?string $device, ?string $card): JournalRow
    {
        return new JournalRow(
            externalId: $id,
            eventTime: CarbonImmutable::parse($time),
            deviceId: $device,
            cardUid: $card,
            result: AccessEvent::RESULT_ALLOWED,
        );
    }

    private function mapDevice(string $device, string $direction): AccessPointDevice
    {
        $point = AccessPoint::query()->firstOrFail();

        return AccessPointDevice::create([
            'source' => self::SOURCE,
            'external_id' => $device,
            'access_point_id' => $point->id,
            'direction' => $direction,
            'name' => 'Считыватель '.$device,
        ]);
    }

    /**
     * @return array{person: Person, student: Student, identity: DigitalIdentity, card: RfidCard}
     */
    private function personWithCard(string $uid): array
    {
        $person = Person::create([
            'last_name' => 'Проходов',
            'first_name' => 'Пётр',
            'status' => 'active',
        ]);

        $group = Group::create([
            'name' => 'ИСП-'.substr($uid, -3),
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);

        $student = Student::create([
            'group_id' => $group->id,
            'person_id' => $person->id,
            'last_name' => 'Проходов',
            'first_name' => 'Пётр',
            'status' => 'active',
        ]);

        $identity = DigitalIdentity::create([
            'person_id' => $person->id,
            'entity_type' => DigitalIdentity::ENTITY_STUDENT,
            'entity_id' => $student->id,
            'token' => (string) Str::uuid(),
            'status' => DigitalIdentity::STATUS_ACTIVE,
            'issued_at' => now(),
        ]);

        $card = RfidCard::create([
            'uid' => $uid,
            'person_id' => $person->id,
            'status' => RfidCard::STATUS_ISSUED,
            'issued_at' => now(),
        ]);

        return ['person' => $person, 'student' => $student, 'identity' => $identity, 'card' => $card];
    }
}
