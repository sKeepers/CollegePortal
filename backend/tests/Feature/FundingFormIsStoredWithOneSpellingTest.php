<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Support\Students\FundingForm;
use Tests\TestCase;

/**
 * У формы финансирования одно написание в базе и привычное слово на экране.
 *
 * Колледж говорит «хозрасчёт», в базе лежит «Договор» — 63 студента на
 * 31.08.2026. Подпись переписана на привычную; без приведения на записи первый
 * же набранный руками «Хозрасчёт» завёл бы второе значение для того же смысла,
 * и отбор «кто на договоре» перестал бы находить половину.
 *
 * Поле свободное (`nullable|string|max:80`), словаря у него нет, поэтому
 * проверяется именно приведение, а не запрет.
 */
class FundingFormIsStoredWithOneSpellingTest extends TestCase
{
    public static function привычныеНаписания(): array
    {
        return [
            'как говорит колледж' => ['Хозрасчёт'],
            'без буквы ё' => ['Хозрасчет'],
            'строчными' => ['хозрасчёт'],
            'с пробелами' => ['  Хозрасчёт  '],
            'как лежит в базе' => ['Договор'],
        ];
    }

    /**
     * @dataProvider привычныеНаписания
     */
    public function test_every_spelling_of_the_contract_form_is_stored_as_one(string $написание): void
    {
        $student = new Student(['funding_form' => $написание]);

        $this->assertSame(
            FundingForm::CONTRACT,
            $student->funding_form,
            "«{$написание}» должно лечь в базу как «Договор», иначе у одного смысла станет два значения",
        );
    }

    public function test_budget_is_left_as_it_is(): void
    {
        $this->assertSame('Бюджет', (new Student(['funding_form' => 'Бюджет']))->funding_form);
        $this->assertSame('Бюджет', (new Student(['funding_form' => ' бюджет ']))->funding_form);
    }

    public function test_an_empty_value_stays_empty(): void
    {
        $this->assertNull((new Student(['funding_form' => '']))->funding_form);
        $this->assertNull((new Student(['funding_form' => '   ']))->funding_form);
    }

    public function test_an_unknown_word_is_not_invented_for(): void
    {
        // Поле свободное: чужое слово сохраняется как есть, а не подгоняется
        // под ближайшее. Догадка здесь была бы хуже незнакомого значения.
        $this->assertSame('Целевое обучение', (new Student(['funding_form' => 'Целевое обучение']))->funding_form);
    }

    public function test_the_screen_shows_the_word_the_college_uses(): void
    {
        $this->assertSame('Хозрасчёт', FundingForm::label('Договор'));
        $this->assertSame('Бюджет', FundingForm::label('Бюджет'));
        $this->assertNull(FundingForm::label(''));
    }
}
