<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\Person;
use App\Models\RfidCard;
use App\Models\RfidCardIssue;
use App\Support\Time\CollegeTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сутки, по которым отбирают, — сутки колледжа, а не сервера.
 *
 * База живёт в UTC, и это правильно. Человек выбирает день по календарю
 * колледжа, и это тоже правильно. Между ними три часа, и пока их не разводили,
 * первые три часа суток уезжали в предыдущий день.
 *
 * Замерено 24.08.2026 на стенде: карта, выданная в 00:17 по колледжу, не
 * попадала в отбор «за 22 августа» — ведомость за день выдачи печаталась
 * пустой при верных данных в базе.
 */
class DayBoundariesInCollegeTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    /** Полночь колледжа в UTC — это девять вечера предыдущего дня. */
    public function test_a_college_day_starts_three_hours_before_the_utc_one(): void
    {
        $this->assertSame('2026-08-21 21:00:00', CollegeTime::dayStart('2026-08-22')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-22 20:59:59', CollegeTime::dayEnd('2026-08-22')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-22 05:00:00', CollegeTime::at('2026-08-22', 8)->format('Y-m-d H:i:s'));
    }

    /**
     * Отметка времени относится к тому дню колледжа, в который она попала.
     *
     * `21:17 UTC` — это `00:17` следующего дня по местным часам, и день у неё
     * следующий. Именно на этом ведомость и теряла строку.
     */
    public function test_an_instant_belongs_to_the_college_day_it_falls_into(): void
    {
        $instant = CarbonImmutable::parse('2026-08-21 21:17:49', 'UTC');

        $this->assertSame('2026-08-22 20:59:59', CollegeTime::dayEnd($instant)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-21 21:00:00', CollegeTime::dayStart($instant)->format('Y-m-d H:i:s'));
    }

    /**
     * Ведомость за день выдачи больше не пустая.
     *
     * Это тот самый случай со стенда, перенесённый в проверку: карта выдана в
     * начале первого ночи, человек ищет её за то число, в которое это
     * произошло по календарю колледжа.
     */
    public function test_the_issue_journal_finds_a_card_handed_out_after_midnight(): void
    {
        $person = Person::create(['last_name' => 'Полуночный', 'first_name' => 'Иван', 'status' => 'active']);

        $card = RfidCard::create([
            'uid' => '0008327739',
            'person_id' => $person->id,
            'status' => RfidCard::STATUS_ISSUED,
            'issued_at' => '2026-08-21 21:17:49',
        ]);

        RfidCardIssue::create([
            'rfid_card_id' => $card->id,
            'person_id' => $person->id,
            // 00:17 по колледжу 22 августа
            'issued_at' => '2026-08-21 21:17:49',
        ]);

        $onTheDay = $this->getJson('/api/rfid-cards/journal?from=2026-08-22&to=2026-08-22')->assertOk();
        $dayBefore = $this->getJson('/api/rfid-cards/journal?from=2026-08-21&to=2026-08-21')->assertOk();

        $this->assertCount(1, $onTheDay->json('data'), 'Карта выдана 22-го по календарю колледжа, а за 22-е не нашлась');
        $this->assertCount(0, $dayBefore->json('data'), 'Карта попала в предыдущий день — границы всё ещё в UTC');
    }

    /**
     * Отчёт по проходам — то же самое на своей колонке.
     *
     * `access_events.event_time` тоже `timestamp`, и на нём висят присутствие,
     * опоздания и ночные отсутствия общежития.
     */
    public function test_the_access_report_counts_a_pass_made_after_midnight(): void
    {
        AccessEvent::create([
            'direction' => AccessEvent::DIRECTION_IN,
            // 00:30 по колледжу 22 августа
            'event_time' => '2026-08-21 21:30:00',
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);

        $onTheDay = $this->getJson('/api/access/reports/events?date=2026-08-22')->assertOk();
        $dayBefore = $this->getJson('/api/access/reports/events?date=2026-08-21')->assertOk();

        $this->assertCount(1, $onTheDay->json('data'), 'Проход в начале первого ночи не попал в свой день');
        $this->assertCount(0, $dayBefore->json('data'), 'Проход уехал в предыдущий день');
    }

    /**
     * Последний час суток колледжа тоже свой.
     *
     * Граница с другого конца: 23:30 по колледжу — это 20:30 UTC того же дня,
     * и в отбор за этот день оно обязано попадать.
     */
    public function test_the_last_hour_of_a_college_day_stays_in_it(): void
    {
        AccessEvent::create([
            'direction' => AccessEvent::DIRECTION_OUT,
            // 23:30 по колледжу 22 августа
            'event_time' => '2026-08-22 20:30:00',
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);

        $this->assertCount(
            1,
            $this->getJson('/api/access/reports/events?date=2026-08-22')->assertOk()->json('data'),
            'Проход в половине двенадцатого ночи выпал из своего дня',
        );
    }
}
