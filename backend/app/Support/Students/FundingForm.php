<?php

namespace App\Support\Students;

/**
 * Как колледж называет форму финансирования и что при этом хранится.
 *
 * Владелец говорит «хозрасчёт», в базе лежит «Договор» — 63 студента на
 * 31.08.2026. Переименовать хранимое значило бы переписать эти строки и все
 * файлы, которыми колледж обменивается; переименовать только подпись — значит
 * получить второе слово для того же смысла, как только кто-нибудь введёт
 * «Хозрасчёт» руками или пришлёт файл со своим словом. Поле свободное:
 * `nullable|string|max:80`, словаря у него нет.
 *
 * Поэтому здесь два действия и они разные: `store()` приводит **входящее** к
 * хранимому слову, `label()` показывает хранимое так, как говорят в колледже.
 * Одно значение, одно написание в базе, привычное слово на экране.
 */
final class FundingForm
{
    public const BUDGET = 'Бюджет';

    /** Что лежит в базе. */
    public const CONTRACT = 'Договор';

    /** Как это называет колледж. */
    public const CONTRACT_LABEL = 'Хозрасчёт';

    /**
     * Привести введённое или пришедшее файлом к хранимому написанию.
     *
     * «Хозрасчёт», «хозрасчет» без буквы «ё», с пробелами по краям — всё это
     * одно и то же слово, и все они должны лечь как «Договор».
     */
    public static function store(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return null;
        }

        $lowered = mb_strtolower(str_replace('ё', 'е', $trimmed));

        return match ($lowered) {
            'хозрасчет', 'хозрасчёт', 'договор', 'платно', 'платная основа' => self::CONTRACT,
            'бюджет', 'бюджетная основа', 'бесплатно' => self::BUDGET,
            default => $trimmed,
        };
    }

    /** Как показать хранимое значение человеку. */
    public static function label(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return null;
        }

        return $trimmed === self::CONTRACT ? self::CONTRACT_LABEL : $trimmed;
    }

    /**
     * Пары «что показать — что сохранить» для выпадающих списков.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public static function options(): array
    {
        return [
            ['label' => self::BUDGET, 'value' => self::BUDGET],
            ['label' => self::CONTRACT_LABEL, 'value' => self::CONTRACT],
        ];
    }
}
