<?php

namespace Tests\Feature;

use App\Models\Diploma;
use App\Models\DiplomaBlank;
use App\Models\Graduate;
use App\Models\Group;
use App\Models\Person;
use App\Models\Student;
use App\Services\Graduation\DiplomaBlankService;
use App\Services\Graduation\DiplomaRegistryService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Номер бланка в дипломе и учёт бланков не расходятся молча.
 *
 * Данные вымышленные. Проверяется то, что печатается: книга регистрации
 * выданных дипломов собирается из полей диплома, а склад бланков живёт своей
 * жизнью рядом. Пока связь между ними была односторонней — от закрепления к
 * диплому и никогда обратно, — расхождение появлялось тремя разными путями, и
 * каждый достижим щелчком на экране.
 *
 * Каждая проверка ниже сначала была запущена на прежнем коде и падала.
 */
class DiplomaNumberComesFromLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_releasing_a_blank_takes_its_number_out_of_the_diploma(): void
    {
        $service = app(DiplomaBlankService::class);
        $this->receive(['number_from' => '800', 'number_to' => '801']);
        $blank = DiplomaBlank::where('number', '800')->first();

        $first = $this->makeGraduate('Первая', withDiploma: true);
        $service->assign($blank, $first);
        $this->assertSame('800', $first->diploma->refresh()->number);

        $service->release($blank->refresh(), null, 'ошиблись номером');

        $this->assertNull($first->diploma->refresh()->series);
        $this->assertNull($first->diploma->refresh()->number);
    }

    public function test_a_released_blank_can_be_assigned_to_someone_else(): void
    {
        // До правки здесь падала не проверка, а сама база: у `diplomas`
        // уникальный ключ по паре «серия + номер», и номер снятого бланка
        // оставался в дипломе первого выпускника.
        $service = app(DiplomaBlankService::class);
        $this->receive(['number_from' => '810', 'number_to' => '811']);
        $blank = DiplomaBlank::where('number', '810')->first();

        $first = $this->makeGraduate('Вторая', withDiploma: true);
        $service->assign($blank, $first);
        $service->release($blank->refresh(), null, 'выпускник не пришёл');

        $second = $this->makeGraduate('Третья', withDiploma: true);
        $service->assign($blank->refresh(), $second);

        $this->assertSame('810', $second->diploma->refresh()->number);
        $this->assertNull($first->diploma->refresh()->number);

        foreach ([$first, $second] as $graduate) {
            $graduate->diploma->refresh()->fill(['status' => 'issued', 'issue_date' => '2026-06-30'])->save();
        }

        // В книге номер стоит ровно один раз — у того, за кем бланк закреплён.
        $blanks = app(DiplomaRegistryService::class)->rows()->pluck('diploma_blank')->all();
        $this->assertSame(['115924 810'], array_values(array_filter($blanks)));
    }

    public function test_a_diploma_created_after_the_blank_takes_its_number_from_the_ledger(): void
    {
        // Обычный порядок работы: сначала склад выдаёт бланк, потом на него
        // выписывают диплом. До правки номер в этом порядке не доезжал никуда,
        // и книга печатала пустую графу при выданном бланке.
        $service = app(DiplomaBlankService::class);
        $this->receive(['number_from' => '820', 'number_to' => '821']);
        $blank = DiplomaBlank::where('number', '820')->first();

        $graduate = $this->makeGraduate('Четвёртая');
        $service->assign($blank, $graduate);
        $service->issue($blank->refresh());

        $graduate->diploma()->create(['status' => 'issued', 'issue_date' => '2026-06-30']);

        $row = app(DiplomaRegistryService::class)->rows()->first();

        $this->assertSame('115924 820', $row['diploma_blank']);
    }

    public function test_a_number_typed_over_the_ledger_is_refused(): void
    {
        $service = app(DiplomaBlankService::class);
        $this->receive(['number_from' => '830', 'number_to' => '831']);
        $blank = DiplomaBlank::where('number', '830')->first();

        $graduate = $this->makeGraduate('Пятая', withDiploma: true);
        $service->assign($blank, $graduate);

        try {
            $graduate->diploma->refresh()->fill(['series' => '115924', 'number' => '999999'])->save();
            $this->fail('Номер, расходящийся с учётом, обязан быть отвергнут.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('берётся из учёта бланков', $e->getMessage());
        }

        $this->assertSame('830', $graduate->diploma->refresh()->number);
    }

    public function test_a_blank_standing_in_another_diploma_is_refused_before_the_database_says_so(): void
    {
        // Ловить нарушение уникальности исключением нельзя: на PostgreSQL
        // упавший запрос отравляет транзакцию, и следующая ошибка приходит не
        // оттуда, где беда. Поэтому спрашиваем заранее.
        $service = app(DiplomaBlankService::class);
        $this->receive(['number_from' => '840', 'number_to' => '841']);
        $blank = DiplomaBlank::where('number', '840')->first();

        $stranger = $this->makeGraduate('Шестая', withDiploma: true);
        $stranger->diploma->refresh()->fill(['series' => '115924', 'number' => '840'])->save();

        $graduate = $this->makeGraduate('Седьмая', withDiploma: true);

        try {
            $service->assign($blank, $graduate);
            $this->fail('Бланк, уже стоящий в чужом дипломе, закреплять нельзя.');
        } catch (UniqueConstraintViolationException) {
            $this->fail('Отказ пришёл от базы, а должен приходить от портала.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('не бывает у двоих', $e->getMessage());
        }

        $this->assertSame(DiplomaBlank::STATUS_STOCK, $blank->refresh()->status);
    }

    public function test_a_diploma_without_a_live_blank_keeps_the_number_it_was_given(): void
    {
        // Обратная проверка: дипломы прежних выпусков заведены до учёта бланков,
        // их номера правило не трогает и не стирает.
        $graduate = $this->makeGraduate('Восьмая');

        $diploma = $graduate->diploma()->create([
            'series' => '117700',
            'number' => '000451',
            'status' => 'issued',
            'issue_date' => '2024-06-28',
        ]);

        $this->assertSame('117700', $diploma->refresh()->series);
        $this->assertSame('000451', $diploma->refresh()->number);
    }

    public function test_two_live_blanks_of_different_kinds_leave_the_number_to_a_human(): void
    {
        // Второй живой бланк того же вида служба запрещает, а видов у диплома
        // три: обычный, с отличием и дубликат. При двух живых бланках разных
        // видов какой из них номер этого диплома — вопрос к человеку, и правило
        // отходит в сторону, а не выбирает само.
        $service = app(DiplomaBlankService::class);
        $this->receive(['number_from' => '850', 'number_to' => '851']);
        $this->receive(['kind' => DiplomaBlank::KIND_DUPLICATE, 'number_from' => '860', 'number_to' => '861']);

        $graduate = $this->makeGraduate('Девятая');
        $service->assign(DiplomaBlank::where('number', '850')->first(), $graduate);
        $service->assign(DiplomaBlank::where('number', '860')->first(), $graduate);

        $diploma = $graduate->diploma()->create(['status' => 'draft']);

        $this->assertNull($diploma->refresh()->number);
    }

    /** @param  array<string, mixed>  $overrides */
    private function receive(array $overrides = []): void
    {
        app(DiplomaBlankService::class)->receive($overrides + [
            'kind' => DiplomaBlank::KIND_DIPLOMA,
            'series' => '115924',
            'number_from' => '1',
            'number_to' => '5',
            'received_at' => '2026-08-28',
            'supplier' => 'Гознак',
        ]);
    }

    private function makeGraduate(string $lastName, bool $withDiploma = false): Graduate
    {
        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'last_name' => $lastName,
            'first_name' => 'Вымышленная',
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
            'last_name' => $lastName,
            'first_name' => 'Вымышленная',
            'birth_date' => '2005-03-14',
            'status' => 'graduated',
        ]);

        $graduate = Graduate::create([
            'person_id' => $person->id,
            'student_id' => $student->id,
            'group_id' => $group->id,
            'graduation_year' => 2026,
            'qualification' => 'Артист, преподаватель',
            'status' => 'draft',
        ]);

        if ($withDiploma) {
            Diploma::create(['graduate_id' => $graduate->id, 'status' => 'draft']);
            $graduate->load('diploma');
        }

        return $graduate;
    }
}
