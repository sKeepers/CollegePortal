<?php

namespace App\Support\Graduation;

use App\Models\Diploma;
use App\Models\DiplomaBlank;
use Illuminate\Validation\ValidationException;

/**
 * Серия и номер бланка в дипломе берутся из учёта бланков.
 *
 * Это одно число, записанное в двух местах: колонки `diplomas.series` и
 * `diplomas.number` — и бланк, закреплённый за выпускником. Пока места
 * независимы, расходятся они молча, а печатается из них книга регистрации: её
 * ведут по закону, в ней расписывается получатель, и по ней потом отвечают на
 * запрос о подлинности диплома.
 *
 * Замерено 28.08.2026 двумя пробами на внесённом дефекте:
 *
 * - диплом, заведённый **после** закрепления бланка, номера не получал вовсе:
 *   бланк в учёте `115924 810` и выдан, а в книге графа пуста;
 * - номер, набранный в карточке диплома руками поверх закреплённого, доезжал
 *   до книги как есть: в учёте `115924 820`, в книге `115924 999999`.
 *
 * Отсюда правило: **у выпускника с живым бланком диплом не хранит другого
 * номера.** Пустые колонки заполняются из учёта, расходящиеся — отказ с обоими
 * номерами в тексте, потому что какой из них верен, знает только человек.
 */
class BlankNumberInDiploma
{
    /**
     * Живой бланк диплома выпускника, если он один.
     *
     * «Живой» — закреплённый или выданный: испорченный и списанный остаются в
     * книге, но номером диплома быть перестают, и замена после порчи разрешена
     * намеренно.
     *
     * «Если он один» — не перестраховка. `DiplomaBlankService` запрещает второй
     * живой бланк **того же вида**, а видов у диплома три: обычный, с отличием
     * и дубликат. Двум живым бланкам разных видов правило не мешает, и какой из
     * них номер этого диплома — вопрос к человеку, а не к запросу.
     */
    public static function liveBlank(?int $graduateId): ?DiplomaBlank
    {
        if ($graduateId === null) {
            return null;
        }

        $live = DiplomaBlank::query()
            ->where('graduate_id', $graduateId)
            ->where('kind', '!=', DiplomaBlank::KIND_SUPPLEMENT)
            ->whereIn('status', [DiplomaBlank::STATUS_ASSIGNED, DiplomaBlank::STATUS_ISSUED])
            ->get();

        return $live->count() === 1 ? $live->first() : null;
    }

    /**
     * Свести диплом с учётом до записи: пустое заполнить, расхождение отвергнуть.
     */
    public static function agree(Diploma $diploma): void
    {
        $blank = self::liveBlank($diploma->graduate_id === null ? null : (int) $diploma->graduate_id);

        if ($blank === null) {
            return;
        }

        if ($diploma->series === $blank->series && $diploma->number === $blank->number) {
            return;
        }

        if (! filled($diploma->series) && ! filled($diploma->number)) {
            $diploma->series = $blank->series;
            $diploma->number = $blank->number;

            return;
        }

        throw ValidationException::withMessages([
            'number' => sprintf(
                'За выпускником закреплён бланк %s, а в дипломе стоит «%s %s». Номер диплома берётся из учёта бланков: снимите закрепление или исправьте номер.',
                $blank->label(),
                (string) $diploma->series,
                (string) $diploma->number,
            ),
        ]);
    }
}
