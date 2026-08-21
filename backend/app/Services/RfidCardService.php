<?php

namespace App\Services;

use App\Models\Person;
use App\Models\RfidCard;
use Illuminate\Validation\ValidationException;

/**
 * Выдача и приём RFID-карт.
 *
 * Правила простые, но их легко нарушить руками, поэтому они здесь, а не в
 * контроллере:
 *
 * - выдать можно только свободную карту. Выдача карты, которая уже у кого-то на
 *   руках, — это молчаливая потеря следа: прежний владелец так и остался бы
 *   записан ушедшим;
 * - у человека не бывает двух действующих карт. Вторая означает, что первую
 *   потеряли и не отметили, и на проходной пройдут обе;
 * - утерянную и списанную карту выдать нельзя, пока её не вернули в оборот.
 *
 * Каждое действие пишется в аудит: карта — материальная ценность, и вопрос «у
 * кого она была в марте» задают всерьёз.
 */
class RfidCardService
{
    public function issue(RfidCard $card, Person $person, ?string $note = null): RfidCard
    {
        if ($card->status === RfidCard::STATUS_ISSUED) {
            throw ValidationException::withMessages([
                'card' => 'Карта уже выдана. Сначала примите её обратно.',
            ]);
        }

        if (in_array($card->status, [RfidCard::STATUS_LOST, RfidCard::STATUS_WRITTEN_OFF], true)) {
            throw ValidationException::withMessages([
                'card' => 'Карта числится утерянной или списанной. Верните её в оборот, прежде чем выдавать.',
            ]);
        }

        $busy = RfidCard::query()
            ->where('person_id', $person->id)
            ->where('status', RfidCard::STATUS_ISSUED)
            ->whereKeyNot($card->id)
            ->first();

        if ($busy !== null) {
            throw ValidationException::withMessages([
                'person_id' => 'У человека уже есть карта на руках — '.$busy->uid.'. Сначала примите её.',
            ]);
        }

        $old = $card->only(['person_id', 'status', 'issued_at', 'returned_at']);

        $card->forceFill([
            'person_id' => $person->id,
            'status' => RfidCard::STATUS_ISSUED,
            'issued_at' => now()->toDateString(),
            'returned_at' => null,
            'note' => $note ?? $card->note,
        ])->save();

        AuditLogService::log('rfid', 'card_issued', $card, $old, $card->only(['person_id', 'status', 'issued_at']), personId: $person->id);

        return $card->fresh('person');
    }

    public function accept(RfidCard $card, ?string $note = null): RfidCard
    {
        $old = $card->only(['person_id', 'status', 'issued_at', 'returned_at']);

        // Человек в карте остаётся: «принята у кого» — это и есть история.
        $card->forceFill([
            'status' => RfidCard::STATUS_STOCK,
            'returned_at' => now()->toDateString(),
            'note' => $note ?? $card->note,
        ])->save();

        AuditLogService::log('rfid', 'card_accepted', $card, $old, $card->only(['person_id', 'status', 'returned_at']));

        return $card->fresh('person');
    }

    /** Сменить состояние карты: утеряна, заблокирована, списана или снова на складе. */
    public function changeStatus(RfidCard $card, string $status, ?string $note = null): RfidCard
    {
        if (! in_array($status, RfidCard::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Неизвестное состояние карты.']);
        }

        if ($status === RfidCard::STATUS_ISSUED) {
            throw ValidationException::withMessages([
                'status' => 'Выдать карту можно только выдачей — тогда портал запишет, кому и когда.',
            ]);
        }

        $old = $card->only(['status', 'note']);
        $card->forceFill(['status' => $status, 'note' => $note ?? $card->note])->save();

        AuditLogService::log('rfid', 'card_status_changed', $card, $old, $card->only(['status', 'note']));

        return $card->fresh('person');
    }
}
