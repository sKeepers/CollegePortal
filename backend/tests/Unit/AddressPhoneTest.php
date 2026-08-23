<?php

namespace Tests\Unit;

use App\Support\People\AddressPhone;
use PHPUnit\Framework\TestCase;

/**
 * Правило «телефон внутри адреса».
 *
 * Данные вымышленные, но формы строк — настоящие: они сняты с 233 карточек
 * стенда 24.08.2026 и с исходного списка учебной части. Улица Тельмана и улица
 * Университетская здесь не для красоты — на них правило и ломается, если
 * снимать маркер «тел» без оглядки на соседнюю букву.
 */
class AddressPhoneTest extends TestCase
{
    public function test_a_number_at_the_tail_needs_no_marker(): void
    {
        $split = AddressPhone::split('СК, г. Михайловск, ул. Гоголя, д.11, кв.15, 89881234567');

        $this->assertNotNull($split);
        $this->assertTrue($split->isClean());
        $this->assertSame('СК, г. Михайловск, ул. Гоголя, д.11, кв.15', $split->address);
        $this->assertSame('89881234567', $split->phone);
    }

    public function test_the_dashed_form_is_the_same_number(): void
    {
        $split = AddressPhone::split('Ставрополь, улица Мира, д. 5, кв. 12, 8-988-123-45-67');

        $this->assertSame('89881234567', $split?->phone);
        $this->assertSame('Ставрополь, улица Мира, д. 5, кв. 12', $split?->address);
    }

    public function test_the_word_before_the_number_goes_with_it(): void
    {
        $split = AddressPhone::split('Ставрополь, улица Мира, д. 5, кв. 12 тел.8-988-123-45-67');

        $this->assertSame('Ставрополь, улица Мира, д. 5, кв. 12', $split?->address);
        $this->assertSame('89881234567', $split?->phone);
    }

    public function test_ten_digits_get_the_country_code(): void
    {
        $split = AddressPhone::split('Ставрополь, улица Мира, д. 5, 9881234567');

        $this->assertSame('89881234567', $split?->phone);
    }

    public function test_a_street_named_like_the_word_survives(): void
    {
        $this->assertNull(AddressPhone::split('Ставрополь, улица Тельмана, д. 7'));
    }

    public function test_a_street_ending_in_the_letter_survives(): void
    {
        $split = AddressPhone::split('Ставрополь, улица Университет, д. 3, 89881234567');

        $this->assertSame('Ставрополь, улица Университет, д. 3', $split?->address);
    }

    public function test_a_lone_marker_letter_does_not_stay_in_the_address(): void
    {
        // Проба десяти карточек 24.08.2026 оставила в адресе одинокую «т»:
        // хвостовую точку срезали раньше, чем сняли маркер, и «т.» перестало им
        // быть. Теперь маркер снимается первым.
        $split = AddressPhone::split('СК, г. Кисловодск, ул. Крылова, д.14/1, кв.28, т.89881234567');

        $this->assertSame('СК, г. Кисловодск, ул. Крылова, д.14/1, кв.28', $split?->address);
        $this->assertSame('89881234567', $split?->phone);
    }

    public function test_a_house_letter_is_not_a_marker(): void
    {
        $split = AddressPhone::split('Ставрополь, улица Мира, д. 5т, 89881234567');

        $this->assertSame('Ставрополь, улица Мира, д. 5т', $split?->address);
    }

    public function test_a_house_number_is_not_a_phone(): void
    {
        $this->assertNull(AddressPhone::split('Ставрополь, ул. Мира, д. 8, кв. 9'));
    }

    public function test_a_postal_index_is_not_a_phone(): void
    {
        $this->assertNull(AddressPhone::split('Ставрополь, ул. Мира, д. 8, 355000'));
    }

    public function test_two_numbers_go_to_a_human(): void
    {
        $address = 'Ставрополь, улица Мира, д. 5, 8-988-123-45-67, 8-900-000-11-22';

        $split = AddressPhone::split($address);

        $this->assertSame('several_phones', $split?->problem);
        $this->assertSame($address, $split?->address);
    }

    public function test_text_after_the_number_goes_to_a_human(): void
    {
        $address = 'Ставрополь, улица Мира, д. 5, 89881234567, Переведена с ОДиУИ Пр№12 от 01.09.2025г';

        $split = AddressPhone::split($address);

        $this->assertSame('text_after_phone', $split?->problem);
        $this->assertSame($address, $split?->address);
    }

    public function test_a_digit_tail_that_is_not_a_number_goes_to_a_human(): void
    {
        // Одиннадцать цифр, начинающихся с 99: такого номера не бывает, а в
        // карточке он есть. Опечатку исправляет человек, а не правило.
        $split = AddressPhone::split('СК, г. Михайловск, ул. Гоголя, д.11, кв.15, 99123456789');

        $this->assertSame('digits_not_a_phone', $split?->problem);
    }

    public function test_an_address_that_is_only_a_number_is_a_number(): void
    {
        $split = AddressPhone::split('89659302901');

        $this->assertTrue((bool) $split?->isClean());
        $this->assertSame('', $split?->address);
        $this->assertSame('89659302901', $split?->phone);
    }

    public function test_a_stump_of_an_address_goes_to_a_human(): void
    {
        $split = AddressPhone::split('д. 5, 89881234567');

        $this->assertSame('address_too_short', $split?->problem);
    }

    public function test_an_empty_string_is_nothing_to_do(): void
    {
        $this->assertNull(AddressPhone::split(null));
        $this->assertNull(AddressPhone::split('   '));
    }
}
