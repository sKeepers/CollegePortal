<?php

namespace App\Support\People;

/**
 * Разбор одной строки адреса: что осталось адресом, что оказалось телефоном и
 * почему разбирать нельзя.
 *
 * Когда `problem` не пуст, `address` содержит исходную строку целиком и трогать
 * её нельзя: разбирает человек.
 */
final readonly class AddressPhoneSplit
{
    public function __construct(
        public string $address,
        public string $phone,
        public ?string $problem = null,
    ) {
    }

    public function isClean(): bool
    {
        return $this->problem === null;
    }
}
