<?php

namespace Tests\Feature;

use App\Models\Diploma;
use App\Models\DiplomaBlank;
use App\Models\DiplomaBlankBatch;
use App\Models\DiplomaBlankEvent;
use App\Models\Graduate;
use App\Models\Group;
use App\Models\Person;
use App\Models\Student;
use App\Services\Graduation\DiplomaBlankService;
use App\Services\Graduation\Exceptions\StrictReportingRecordIsNeverDeleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Учёт бланков строгой отчётности.
 *
 * Данные вымышленные. Проверяется прежде всего то, ради чего учёт и заводится:
 * **номер бланка не бывает свободным заново**. Испорченный и списанный — это
 * конец пути, и вернуться из них нельзя ни в наличие, ни к выпускнику. Если бы
 * можно было, книга регистрации перестала бы быть книгой: один номер значился
 * бы за двумя людьми.
 */
class DiplomaBlankLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_batch_expands_into_blanks_keeping_leading_zeros(): void
    {
        $batch = $this->receive(['number_from' => '0000123', 'number_to' => '0000127']);

        $this->assertSame(5, $batch->quantity);
        $this->assertSame(5, DiplomaBlank::count());
        $this->assertSame(
            ['0000123', '0000124', '0000125', '0000126', '0000127'],
            DiplomaBlank::orderBy('number')->pluck('number')->all(),
        );
        $this->assertSame(DiplomaBlank::STATUS_STOCK, DiplomaBlank::first()->status);
    }

    public function test_every_blank_of_a_batch_gets_its_first_event(): void
    {
        $this->receive(['number_from' => '1', 'number_to' => '3']);

        $this->assertSame(3, DiplomaBlankEvent::where('action', DiplomaBlankEvent::ACTION_RECEIVED)->count());
    }

    public function test_a_batch_that_overlaps_an_existing_number_is_refused_whole(): void
    {
        $this->receive(['number_from' => '10', 'number_to' => '12']);

        try {
            $this->receive(['number_from' => '12', 'number_to' => '20']);
            $this->fail('Пересекающаяся партия должна быть отвергнута целиком.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('уже заведены', $e->getMessage());
        }

        // Ни одного бланка из второй партии: частично принятая партия не сойдётся
        // с накладной, и понять, чего не хватает, будет неоткуда.
        $this->assertSame(3, DiplomaBlank::count());
        $this->assertSame(1, DiplomaBlankBatch::count());
    }

    public function test_a_range_bigger_than_the_cap_looks_like_a_typo(): void
    {
        $this->expectException(ValidationException::class);

        $this->receive(['number_from' => '1', 'number_to' => (string) (DiplomaBlankService::MAX_BATCH + 2)]);
    }

    public function test_the_number_travels_into_the_diploma_when_the_blank_is_assigned(): void
    {
        $this->receive(['number_from' => '500', 'number_to' => '501']);
        $blank = DiplomaBlank::orderBy('number')->first();
        $graduate = $this->makeGraduate(withDiploma: true);

        app(DiplomaBlankService::class)->assign($blank, $graduate);

        $blank->refresh();
        $this->assertSame(DiplomaBlank::STATUS_ASSIGNED, $blank->status);
        $this->assertSame($graduate->id, $blank->graduate_id);
        $this->assertSame('500', $graduate->diploma->refresh()->number);
    }

    public function test_a_spoiled_blank_never_returns_to_stock(): void
    {
        // Ради этого правила учёт и существует. Если испорченный бланк можно
        // вернуть в наличие, его номер однажды достанется второму человеку.
        $this->receive(['number_from' => '600', 'number_to' => '601']);
        $blank = DiplomaBlank::orderBy('number')->first();
        $service = app(DiplomaBlankService::class);

        $service->spoil($blank, 'принтер зажевал лист');

        $this->assertSame(DiplomaBlank::STATUS_SPOILED, $blank->refresh()->status);

        foreach ([DiplomaBlank::STATUS_STOCK, DiplomaBlank::STATUS_ASSIGNED, DiplomaBlank::STATUS_ISSUED] as $forbidden) {
            $this->assertTransitionRefused($blank, $forbidden);
        }
    }

    public function test_a_written_off_blank_is_the_end_of_the_road(): void
    {
        $this->receive(['number_from' => '700', 'number_to' => '701']);
        $blank = DiplomaBlank::orderBy('number')->first();
        $service = app(DiplomaBlankService::class);

        $service->spoil($blank, 'ошибка при заполнении');
        $service->writeOff($blank, 'Акт № 4 от 24.08.2026', 'комиссия');

        $blank->refresh();
        $this->assertSame(DiplomaBlank::STATUS_WRITTEN_OFF, $blank->status);
        $this->assertSame('Акт № 4 от 24.08.2026', $blank->write_off_act);

        foreach (DiplomaBlank::STATUSES as $forbidden) {
            if ($forbidden !== DiplomaBlank::STATUS_WRITTEN_OFF) {
                $this->assertTransitionRefused($blank, $forbidden);
            }
        }
    }

    public function test_an_issued_blank_cannot_be_touched_again(): void
    {
        $blank = $this->issuedBlank();

        foreach ([DiplomaBlank::STATUS_STOCK, DiplomaBlank::STATUS_SPOILED, DiplomaBlank::STATUS_ASSIGNED] as $forbidden) {
            $this->assertTransitionRefused($blank, $forbidden);
        }
    }

    public function test_a_whole_blank_cannot_be_written_off_without_being_spoiled_first(): void
    {
        $this->receive(['number_from' => '800', 'number_to' => '801']);
        $blank = DiplomaBlank::orderBy('number')->first();

        $this->expectException(ValidationException::class);

        app(DiplomaBlankService::class)->writeOff($blank, 'Акт № 5');
    }

    public function test_a_blank_is_never_deleted(): void
    {
        $this->receive(['number_from' => '900', 'number_to' => '901']);

        $this->expectException(StrictReportingRecordIsNeverDeleted::class);

        DiplomaBlank::first()->delete();
    }

    public function test_a_batch_is_never_deleted(): void
    {
        $this->receive(['number_from' => '910', 'number_to' => '911']);

        $this->expectException(StrictReportingRecordIsNeverDeleted::class);

        DiplomaBlankBatch::first()->delete();
    }

    public function test_a_movement_is_never_deleted(): void
    {
        $this->receive(['number_from' => '920', 'number_to' => '921']);

        $this->expectException(StrictReportingRecordIsNeverDeleted::class);

        DiplomaBlankEvent::first()->delete();
    }

    public function test_a_replacement_after_a_spoiled_blank_is_allowed(): void
    {
        $this->receive(['number_from' => '930', 'number_to' => '931']);
        $graduate = $this->makeGraduate(withDiploma: true);
        $service = app(DiplomaBlankService::class);

        $first = DiplomaBlank::where('number', '930')->first();
        $second = DiplomaBlank::where('number', '931')->first();

        $service->assign($first, $graduate);
        $service->spoil($first, 'испорчен при печати');
        $service->assign($second, $graduate);

        $this->assertSame(DiplomaBlank::STATUS_SPOILED, $first->refresh()->status);
        $this->assertSame(DiplomaBlank::STATUS_ASSIGNED, $second->refresh()->status);
        $this->assertSame('931', $graduate->diploma->refresh()->number);

        // Испорченный не исчез и остался за выпускником: по нему отчитываются.
        $this->assertSame($graduate->id, $first->graduate_id);
    }

    public function test_a_second_live_blank_for_one_graduate_is_refused(): void
    {
        $this->receive(['number_from' => '940', 'number_to' => '941']);
        $graduate = $this->makeGraduate(withDiploma: true);
        $service = app(DiplomaBlankService::class);

        $service->assign(DiplomaBlank::where('number', '940')->first(), $graduate);

        $this->expectException(ValidationException::class);

        $service->assign(DiplomaBlank::where('number', '941')->first(), $graduate);
    }

    public function test_the_balance_counts_by_kind_series_and_status(): void
    {
        $this->receive(['number_from' => '1', 'number_to' => '4']);
        $service = app(DiplomaBlankService::class);
        $service->spoil(DiplomaBlank::where('number', '1')->first(), 'смят');

        $balance = $service->balance();

        $this->assertCount(1, $balance);
        $this->assertSame(4, $balance[0]['total']);
        $this->assertSame(3, $balance[0][DiplomaBlank::STATUS_STOCK]);
        $this->assertSame(1, $balance[0][DiplomaBlank::STATUS_SPOILED]);
    }

    private function assertTransitionRefused(DiplomaBlank $blank, string $to): void
    {
        $service = app(DiplomaBlankService::class);
        $blank->refresh();

        try {
            match ($to) {
                DiplomaBlank::STATUS_STOCK => $service->release($blank),
                DiplomaBlank::STATUS_ASSIGNED => $service->assign($blank, $this->makeGraduate()),
                DiplomaBlank::STATUS_ISSUED => $service->issue($blank),
                DiplomaBlank::STATUS_SPOILED => $service->spoil($blank, 'повторно'),
                DiplomaBlank::STATUS_WRITTEN_OFF => $service->writeOff($blank, 'Акт № 9'),
            };

            $this->fail(sprintf('Переход в «%s» из «%s» обязан быть запрещён.', $to, $blank->status));
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function issuedBlank(): DiplomaBlank
    {
        $this->receive(['number_from' => '750', 'number_to' => '751']);
        $blank = DiplomaBlank::orderBy('number')->first();
        $service = app(DiplomaBlankService::class);
        $service->assign($blank, $this->makeGraduate(withDiploma: true));
        $service->issue($blank);

        return $blank->refresh();
    }

    /** @param  array<string, mixed>  $overrides */
    private function receive(array $overrides = []): DiplomaBlankBatch
    {
        return app(DiplomaBlankService::class)->receive($overrides + [
            'kind' => DiplomaBlank::KIND_DIPLOMA,
            'series' => '115924',
            'number_from' => '1',
            'number_to' => '5',
            'received_at' => '2026-08-24',
            'supplier' => 'Гознак',
            'invoice_number' => 'Н-17',
        ]);
    }

    private function makeGraduate(bool $withDiploma = false): Graduate
    {
        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'last_name' => 'Ковалёва',
            'first_name' => 'Полина',
            'birth_date' => '2005-03-14',
            'status' => 'active',
        ]);

        $group = Group::firstOrCreate(
            ['name' => 'Хореографическое творчество, набор 2022'],
            ['specialty' => 'Народное художественное творчество', 'year_start' => 2022],
        );

        $student = Student::create([
            'person_id' => $person->id,
            'group_id' => $group->id,
            'last_name' => 'Ковалёва',
            'first_name' => 'Полина',
            'birth_date' => '2005-03-14',
            'status' => 'graduated',
        ]);

        $graduate = Graduate::create([
            'person_id' => $person->id,
            'student_id' => $student->id,
            'group_id' => $group->id,
            'graduation_year' => 2026,
            'qualification' => 'Руководитель любительского творческого коллектива',
            'status' => 'draft',
        ]);

        if ($withDiploma) {
            Diploma::create(['graduate_id' => $graduate->id, 'status' => 'draft']);
            $graduate->load('diploma');
        }

        return $graduate;
    }
}
