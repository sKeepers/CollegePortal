<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\Person;
use App\Models\RfidCard;
use App\Models\RfidCardIssue;
use App\Support\Time\CollegeTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use ZipArchive;

/**
 * Во сколько человек прошёл, во столько и напечатано.
 *
 * Утверждение здесь именно такое, словами, а не «в выгрузке стоит строка
 * `09:15`». Разница важна: строку можно привести в соответствие с любым
 * поведением, подправив ожидание, и прогон останется зелёным. Смысл подправить
 * нельзя — его можно только нарушить.
 *
 * Найдено 30.08.2026: отчёт проходной **отбирал** события сутками колледжа, а
 * **печатал** их время в UTC. Два календаря внутри одной ручки, и проход в
 * 09:15 уходил на лист как 06:15. Прежняя проверка этого не видела, потому что
 * её приспособление сеяло событие тоже в UTC: обе стороны ошибались одинаково,
 * и утверждение выглядело верным. **Две ошибки, гасящие друг друга, читаются
 * как правильность.**
 */
class DocumentsShowCollegeHoursTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_a_pass_at_quarter_past_nine_prints_as_quarter_past_nine(): void
    {
        // Событие задаётся по часам колледжа и хранится, как хранится всё в
        // портале, — в UTC. Именно так приходят настоящие проходы.
        $moment = CollegeTime::at('2026-09-10', 9, 15);

        $this->assertSame('06:15', $moment->format('H:i'), 'приспособление должно хранить момент в UTC, иначе проверка ничего не докажет');

        AccessEvent::create([
            'entity_type' => 'student',
            'entity_id' => 1,
            'direction' => AccessEvent::DIRECTION_IN,
            'event_time' => $moment,
            'access_point' => 'Главный вход',
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);

        $csv = $this->get('/api/access/reports/events?date_from=2026-09-10&date_to=2026-09-10&export=csv')
            ->assertOk()
            ->streamedContent();

        // Час на листе — местный: человек прошёл в четверть десятого утра.
        $this->assertStringContainsString('10.09.2026;09:15;', $csv);
        // И прямо: часа сервера на листе быть не должно.
        $this->assertStringNotContainsString(';06:15;', $csv);
    }

    public function test_the_card_journal_workbook_prints_college_hours_too(): void
    {
        // Ведомость выдачи карт — второй документ, где стоял час сервера.
        // Прежняя проверка этой выгрузки убеждалась только в том, что ответ
        // начинается с «PK», то есть что это zip. Про содержимое она не
        // утверждала ничего, и подмена часа прошла бы мимо неё.
        $person = Person::create(['last_name' => 'Выгружаемый', 'first_name' => 'Пётр', 'status' => 'active']);
        $card = RfidCard::create(['uid' => '0000000901', 'status' => RfidCard::STATUS_ISSUED, 'person_id' => $person->id]);

        RfidCardIssue::create([
            'rfid_card_id' => $card->id,
            'person_id' => $person->id,
            'issued_at' => CollegeTime::at('2026-09-10', 9, 15),
        ]);

        $content = $this->get('/api/rfid-cards/journal/export')->assertOk()->getContent();

        $this->assertStringContainsString('10.09.2026 09:15', $this->workbookText($content));
    }

    /**
     * Текст книги xlsx: строки лежат в `xl/sharedStrings.xml` открытым текстом.
     *
     * Читаем книгу, а не доверяем тому, что ответ начинается с «PK»: заголовок
     * zip подтверждает формат и молчит о содержимом.
     */
    private function workbookText(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $content);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'ответ не открывается как книга xlsx');
        $text = (string) $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        @unlink($path);

        return $text;
    }

    public function test_a_pass_after_midnight_keeps_its_college_date(): void
    {
        // Самый неочевидный край: 21:30 UTC — это уже половина первого ночи
        // следующего дня по колледжу. Дата на листе обязана быть завтрашней,
        // иначе документ отправит человека искать проход не в тот день.
        $moment = Carbon::parse('2026-09-10 21:30:00');

        AccessEvent::create([
            'entity_type' => 'student',
            'entity_id' => 1,
            'direction' => AccessEvent::DIRECTION_IN,
            'event_time' => $moment,
            'access_point' => 'Главный вход',
            'result' => AccessEvent::RESULT_ALLOWED,
        ]);

        $csv = $this->get('/api/access/reports/events?date_from=2026-09-11&date_to=2026-09-11&export=csv')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('11.09.2026;00:30;', $csv);
    }
}
