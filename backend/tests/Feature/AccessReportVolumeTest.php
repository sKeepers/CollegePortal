<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\Student;
use App\Support\Time\CollegeTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Отчёт проходной на объёме больше прежнего предела в 1000 событий.
 *
 * Находка 5 аудита от 08.08.2026: отбор начинался с `limit(1000)`, и по этой
 * тысяче считались сводка, поиск, фильтр «только опоздавшие» и выгрузка.
 * На демонстрационных данных сводка показывала ровно 1000 событий за день там,
 * где их было 1269. Тест держит объём заведомо выше предела: на сотне строк
 * такая ошибка не воспроизводится вовсе.
 */
class AccessReportVolumeTest extends TestCase
{
    use RefreshDatabase;

    private const VOLUME = 1400;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_summary_counts_every_event_not_just_the_first_thousand(): void
    {
        $this->seedVolume();

        $this->getJson('/api/access/reports/summary')
            ->assertOk()
            ->assertJsonPath('data.today_events', self::VOLUME)
            ->assertJsonPath('data.total_events', self::VOLUME)
            ->assertJsonPath('data.entries', self::VOLUME - 40)
            ->assertJsonPath('data.denied', 40);
    }

    /**
     * Список на экране остаётся коротким — но теперь рядом едет общее число,
     * иначе обрезанный список читается как полный.
     */
    public function test_screen_list_stays_short_and_reports_the_real_total(): void
    {
        $this->seedVolume();

        $this->getJson('/api/access/reports/events')
            ->assertOk()
            ->assertJsonCount(200, 'data')
            ->assertJsonPath('meta.total', self::VOLUME)
            ->assertJsonPath('meta.limit', 200)
            ->assertJsonPath('meta.truncated', true);
    }

    /**
     * Человек, чьи проходы не попали в первую тысячу, обязан находиться поиском.
     * Раньше поиск перебирал уже выбранную тысячу и такого человека не видел.
     */
    public function test_search_finds_a_person_whose_events_fall_outside_the_first_thousand(): void
    {
        $this->seedVolume();
        $identity = $this->createStudentIdentity('Одинцова', 'Марина');

        // Событие самое старое из всех: в выборку «последняя тысяча» оно не попадает.
        AccessEvent::create([
            'digital_identity_id' => $identity->id,
            'entity_type' => 'student',
            'entity_id' => $identity->entity_id,
            'direction' => AccessEvent::DIRECTION_IN,
            'event_time' => Carbon::today()->setTime(0, 1),
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);

        $this->getJson('/api/access/reports/events?search=Одинцова')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.owner.last_name', 'Одинцова');
    }

    /**
     * Выгрузка отдаёт всё, что подошло под фильтры. До исправления в файл
     * попадала та же обрезанная тысяча, и это никак не было видно.
     */
    public function test_export_streams_every_matching_row(): void
    {
        $this->seedVolume();

        $response = $this->get('/api/access/reports/events?export=csv');
        $response->assertOk();

        $lines = array_filter(explode("\n", trim($response->streamedContent())));

        // Строк ровно столько же плюс заголовок.
        $this->assertCount(self::VOLUME + 1, $lines);
    }

    public function test_search_without_matches_returns_nothing_instead_of_ignoring_the_filter(): void
    {
        $this->seedVolume();

        $this->getJson('/api/access/reports/events?search=Такогочеловеканет')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    private function seedVolume(): void
    {
        $identity = $this->createStudentIdentity('Иванов', 'Дмитрий');
        $now = now();
        // Сутки берутся у того же источника, что и код под проверкой.
        // `Carbon::today()` здесь — сутки **UTC**, а сводка с 28.08.2026
        // считает «сегодня» сутками колледжа (`Europe/Moscow`). Три часа в
        // сутки календари не совпадают, и тест падал именно в них: замер
        // 29.08.2026, UTC 21:14 — это уже 00:14 30-го по Москве, посеянные
        // «сегодня в 08:00 UTC» события оказывались за тринадцать часов до
        // начала суток колледжа, и `today_events` приходил нулём.
        $base = CollegeTime::at(CollegeTime::todayDate(), 8);
        $rows = [];

        foreach (range(1, self::VOLUME) as $index) {
            $rows[] = [
                'digital_identity_id' => $identity->id,
                'entity_type' => 'student',
                'entity_id' => $identity->entity_id,
                'direction' => AccessEvent::DIRECTION_IN,
                // Время в пределах сегодняшнего дня **колледжа**, чтобы
                // счётчик «за сегодня» считал их все. Прежняя редакция обещала
                // это же словами, а держалась на совпадении часовых поясов.
                'event_time' => $base->addSeconds($index * 20),
                'result' => $index % 35 === 0 ? AccessEvent::RESULT_DENIED : AccessEvent::RESULT_ALLOWED,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            AccessEvent::query()->insert($chunk);
        }
    }

    private function createStudentIdentity(string $lastName, string $firstName): DigitalIdentity
    {
        $group = Group::query()->firstOrCreate(
            ['name' => 'ИСП-101'],
            ['specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026],
        );

        $student = Student::create([
            'group_id' => $group->id,
            'last_name' => $lastName,
            'first_name' => $firstName,
            'status' => 'active',
        ]);

        return DigitalIdentity::create([
            'entity_type' => 'student',
            'entity_id' => $student->id,
            'token' => (string) Str::uuid(),
            'status' => 'active',
            'issued_at' => now(),
        ]);
    }
}
