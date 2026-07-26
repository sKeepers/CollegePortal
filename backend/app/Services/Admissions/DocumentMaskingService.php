<?php

namespace App\Services\Admissions;

class DocumentMaskingService
{
    public function snils(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        if ($digits === '') {
            return null;
        }

        $tail = substr($digits, -5);

        return '***-***-'.substr($tail, 0, 3).' '.substr($tail, 3, 2);
    }

    public function documentNumber(?string $series, ?string $number): ?string
    {
        $numberDigits = preg_replace('/\D+/', '', (string) $number) ?: (string) $number;
        $numberTail = mb_substr($numberDigits, -4);
        $maskedNumber = str_repeat('*', max(0, mb_strlen($numberDigits) - 4)).$numberTail;

        if (! filled($series)) {
            return filled($number) ? $maskedNumber : null;
        }

        return '**** '.$maskedNumber;
    }
}
