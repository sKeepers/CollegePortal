<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\RfidCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Привязка карт СКУД по кадровой выгрузке.
 *
 * Главное здесь — не то, что карта привязалась, а то, что она **не**
 * привязалась там, где привязывать нельзя: выгрузка без имён, тёзки, человек
 * которого в портале нет. Ошибка в эту сторону обнаруживается у турникета
 * чужой картой, а не в отчёте загрузки.
 */
class ImportRfidCardsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_half_exported_file_is_refused_whole(): void
    {
        // Ровно то, что владелец принёс 28.08.2026: 636 строк, вместо фамилий
        // `Студент1`…`Студент636`, на месте имени и отчества слова шаблона, а
        // номера карт настоящие. Файл выглядит рабочим — в этом и беда.
        $file = $this->csv([
            "Ф'Студент1'Имя';'Отчество';1580899;сотрудник;Студенты;",
            "Ф'Студент2'Имя';'Отчество';1580900;сотрудник;Студенты;",
        ]);

        $this->artisan('identity:import-cards', ['file' => $file])
            ->expectsOutputToContain('Выгрузка неполна')
            ->assertExitCode(1);

        $this->assertDatabaseCount('rfid_cards', 0);
    }

    public function test_one_bad_row_refuses_the_whole_file(): void
    {
        // Отказ именно от файла целиком, а не пропуск подозрительной строки:
        // наполовину выгруженное нельзя загрузить наполовину, и хорошая
        // строка рядом с местом шаблона ничего не доказывает.
        Person::create(['last_name' => 'Иванов', 'first_name' => 'Иван', 'middle_name' => 'Иванович', 'status' => 'active']);

        $file = $this->csv([
            "Ф'Иванов'Иван';'Иванович';1234567;сотрудник;Администрация;",
            "Ф'Студент5'Имя';'Отчество';7654321;сотрудник;Студенты;",
        ]);

        $this->artisan('identity:import-cards', ['file' => $file])->assertExitCode(1);

        $this->assertDatabaseCount('rfid_cards', 0);
    }

    public function test_a_card_is_bound_and_padded_to_ten_digits(): void
    {
        $person = Person::create(['last_name' => 'Иванов', 'first_name' => 'Иван', 'middle_name' => 'Иванович', 'status' => 'active']);

        $file = $this->csv(["Ф'Иванов'Иван';'Иванович';1234567;сотрудник;Администрация;"]);

        $this->artisan('identity:import-cards', ['file' => $file])
            ->expectsOutputToContain('Карт привязано: 1')
            ->assertExitCode(0);

        // Семизначный номер из выгрузки и десятизначный в портале — один и тот
        // же номер: ведущие нули в выгрузке срезаны. Подтверждено владельцем
        // 28.08.2026 словами «8327739 можно записать как 0008327739».
        $this->assertDatabaseHas('rfid_cards', [
            'uid' => '0001234567',
            'person_id' => $person->id,
            'status' => RfidCard::STATUS_ISSUED,
        ]);
    }

    public function test_namesakes_are_skipped_by_name_not_guessed(): void
    {
        Person::create(['last_name' => 'Михайлов', 'first_name' => 'Дмитрий', 'middle_name' => 'Петрович', 'status' => 'active']);
        Person::create(['last_name' => 'Михайлов', 'first_name' => 'Дмитрий', 'middle_name' => 'Петрович', 'status' => 'active']);

        $file = $this->csv(["Ф'Михайлов'Дмитрий';'Петрович';1234567;сотрудник;Преподаватели;"]);

        $this->artisan('identity:import-cards', ['file' => $file])
            ->expectsOutputToContain('Тёзки, пропущены: 1')
            ->expectsOutputToContain('Михайлов Дмитрий Петрович')
            ->assertExitCode(0);

        $this->assertDatabaseCount('rfid_cards', 0);
    }

    public function test_a_person_who_is_not_in_the_portal_is_named_not_created(): void
    {
        $file = $this->csv(["Ф'Петров'Пётр';'Петрович';7654321;сотрудник;Администрация;"]);

        $this->artisan('identity:import-cards', ['file' => $file])
            ->expectsOutputToContain('Человек не найден: 1')
            ->expectsOutputToContain('Петров Пётр Петрович')
            ->assertExitCode(0);

        // Карта «на будущего человека» не заводится: номер, ни к кому не
        // привязанный, потом ничем не отличить от потерянного.
        $this->assertDatabaseCount('rfid_cards', 0);
        $this->assertDatabaseCount('people', 0);
    }

    public function test_the_middle_name_is_what_separates_namesakes(): void
    {
        // Пара «фамилия + имя» совпадает, отчество различает. Если бы отбор
        // шёл по двум частям, обе строки ушли бы в «тёзки» и ни одна карта не
        // привязалась бы.
        $first = Person::create(['last_name' => 'Сидоренко', 'first_name' => 'Алина', 'middle_name' => 'Сергеевна', 'status' => 'active']);
        $second = Person::create(['last_name' => 'Сидоренко', 'first_name' => 'Алина', 'middle_name' => 'Олеговна', 'status' => 'active']);

        $file = $this->csv([
            "Ф'Сидоренко'Алина';'Сергеевна';1111111;сотрудник;Администрация;",
            "Ф'Сидоренко'Алина';'Олеговна';2222222;сотрудник;Администрация;",
        ]);

        $this->artisan('identity:import-cards', ['file' => $file])
            ->expectsOutputToContain('Карт привязано: 2')
            ->assertExitCode(0);

        $this->assertDatabaseHas('rfid_cards', ['uid' => '0001111111', 'person_id' => $first->id]);
        $this->assertDatabaseHas('rfid_cards', ['uid' => '0002222222', 'person_id' => $second->id]);
    }

    public function test_a_second_card_for_the_same_person_is_bound_and_named(): void
    {
        // В выгрузке 28.08.2026 такая строка есть: один преподаватель числится
        // с двумя картами, обе сошлись по ФИО. Обе привязываются — владелец в
        // тот же день сказал, что на человека бывает записано несколько карт,
        // — но вторая идёт в отчёт отдельной строкой: если две карты сошлись
        // на одном человеке по ошибке сопоставления, молчание сделало бы эту
        // ошибку ненаходимой.
        $person = Person::create(['last_name' => 'Трубач', 'first_name' => 'Екатерина', 'middle_name' => 'Ивановна', 'status' => 'active']);

        $file = $this->csv([
            "Ф'Трубач'Екатерина';'Ивановна';1111111;сотрудник;Преподаватели;",
            "Ф'Трубач'Екатерина';'Ивановна';2222222;сотрудник;Преподаватели;",
        ]);

        $this->artisan('identity:import-cards', ['file' => $file])
            ->expectsOutputToContain('Карт привязано: 2')
            ->expectsOutputToContain('Из них вторая карта тому же человеку: 1')
            ->expectsOutputToContain('была 0001111111, добавлена 2222222')
            ->assertExitCode(0);

        $this->assertDatabaseHas('rfid_cards', ['uid' => '0001111111', 'person_id' => $person->id]);
        $this->assertDatabaseHas('rfid_cards', ['uid' => '0002222222', 'person_id' => $person->id]);
    }

    public function test_a_digit_instead_of_a_patronymic_means_another_card_for_the_same_person(): void
    {
        // Владелец 28.08.2026: «на человека оказалось записано больше одной
        // карты, поэтому добавил цифру». В кадровой выгрузке так помечены семь
        // строк: три карты одного преподавателя, две другого, две третьего.
        // Цифра — пометка карты, а не отчество, и отбрасывается только она.
        $person = Person::create(['last_name' => 'Михайлов', 'first_name' => 'Дмитрий', 'status' => 'active']);

        $file = $this->csv([
            "Ф'Михайлов'Дмитрий';'1';1111111;сотрудник;Преподаватели;",
            "Ф'Михайлов'Дмитрий';'2';2222222;сотрудник;Преподаватели;",
            "Ф'Михайлов'Дмитрий';'3';3333333;сотрудник;Преподаватели;",
        ]);

        $this->artisan('identity:import-cards', ['file' => $file])
            ->expectsOutputToContain('Карт привязано: 3')
            ->assertExitCode(0);

        foreach (['0001111111', '0002222222', '0003333333'] as $uid) {
            $this->assertDatabaseHas('rfid_cards', ['uid' => $uid, 'person_id' => $person->id]);
        }
    }

    public function test_a_digit_patronymic_still_refuses_when_the_name_is_shared(): void
    {
        // Отбросив отчество, легко потерять и отказ. Проверяем, что не
        // потеряли: если под парой «фамилия + имя» окажется двое, строка
        // по-прежнему уходит в тёзки. Замер по контингенту стенда — таких
        // четверо из 596, то есть случай не выдуманный.
        Person::create(['last_name' => 'Сидоренко', 'first_name' => 'Алина', 'middle_name' => 'Сергеевна', 'status' => 'active']);
        Person::create(['last_name' => 'Сидоренко', 'first_name' => 'Алина', 'middle_name' => 'Олеговна', 'status' => 'active']);

        $file = $this->csv(["Ф'Сидоренко'Алина';'1';1111111;сотрудник;Преподаватели;"]);

        $this->artisan('identity:import-cards', ['file' => $file])
            ->expectsOutputToContain('Тёзки, пропущены: 1')
            ->assertExitCode(0);

        $this->assertDatabaseCount('rfid_cards', 0);
    }

    public function test_running_the_same_file_twice_changes_nothing_and_says_so(): void
    {
        // Загрузку перезапускают: файл уточнили, дубли слили, часть строк
        // довязали. Второй проход по тем же строкам не отказ, а «уже сделано»
        // — без этого повторный запуск печатал бы 236 «Карта уже выдана» и
        // выглядел бы полной неудачей, ничего при этом не сломав.
        Person::create(['last_name' => 'Иванов', 'first_name' => 'Иван', 'middle_name' => 'Иванович', 'status' => 'active']);
        $file = $this->csv(["Ф'Иванов'Иван';'Иванович';1234567;сотрудник;Администрация;"]);

        $this->artisan('identity:import-cards', ['file' => $file])
            ->expectsOutputToContain('Карт привязано: 1')
            ->assertExitCode(0);

        $this->artisan('identity:import-cards', ['file' => $file])
            ->expectsOutputToContain('Карт привязано: 0')
            ->expectsOutputToContain('Уже было привязано раньше: 1')
            ->expectsOutputToContain('Отказов: 0')
            ->assertExitCode(0);

        $this->assertDatabaseCount('rfid_cards', 1);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        Person::create(['last_name' => 'Иванов', 'first_name' => 'Иван', 'middle_name' => 'Иванович', 'status' => 'active']);

        $file = $this->csv(["Ф'Иванов'Иван';'Иванович';1234567;сотрудник;Администрация;"]);

        $this->artisan('identity:import-cards', ['file' => $file, '--dry-run' => true])
            ->expectsOutputToContain('Карт привязано: 1')
            ->expectsOutputToContain('ничего не записано')
            ->assertExitCode(0);

        $this->assertDatabaseCount('rfid_cards', 0);
    }

    /** Файл в той же кодировке, что и настоящая выгрузка: CP1251. */
    private function csv(array $lines): string
    {
        $text = "Фамилия;Имя;Отчество;Карта;Статус;Подразделение;\n".implode("\n", $lines)."\n";
        $path = tempnam(sys_get_temp_dir(), 'cdx').'.csv';
        file_put_contents($path, mb_convert_encoding($text, 'CP1251', 'UTF-8'));

        return $path;
    }
}
