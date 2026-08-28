<?php

namespace App\Services;

use App\Models\Person;
use App\Models\RfidCard;
use App\Models\RfidCardIssue;
use App\Support\Rfid\CardNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
 * Каждое действие открывает или закрывает строку журнала выдач — того самого,
 * что комендант вёл в тетради. Состояние в самой карте остаётся, но оно снимок:
 * история живёт в журнале, и печатается тоже он.
 */
class RfidCardService
{
    /**
     * Привязать карту к человеку по номеру со считывателя.
     *
     * Главный путь всей работы: комендант нашёл человека, поднёс карту, нажал
     * «Записать». Отдельного шага «завести карту» здесь нет намеренно — номер
     * портал узнаёт от считывателя, и незнакомая карта заводится сама.
     */
    public function bind(Person $person, string $uid, ?string $label = null, ?string $note = null): RfidCard
    {
        $number = CardNumber::normalize($uid);
        $card = RfidCard::query()->firstWhere('uid', $number);

        if ($card === null) {
            // insertOrIgnore, а не создание с перехватом исключения: на
            // PostgreSQL упавший INSERT отравляет транзакцию целиком, и падает
            // потом не там, где ошибка.
            RfidCard::query()->insertOrIgnore([
                'uid' => $number,
                'uid_raw' => trim($uid),
                'label' => $label,
                'status' => RfidCard::STATUS_STOCK,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $card = RfidCard::query()->firstWhere('uid', $number);
        }

        if ($card === null) {
            throw ValidationException::withMessages([
                'uid' => 'Не удалось завести карту с этим номером. Поднесите её к считывателю ещё раз.',
            ]);
        }

        if ($label !== null && $card->label === null) {
            $card->forceFill(['label' => $label])->save();
        }

        return $this->issue($card, $person, $note);
    }

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

        // Вторая карта тому же человеку **разрешена**. Раньше здесь стоял
        // отказ «у человека уже есть карта на руках»; снят 28.08.2026 по слову
        // владельца: «на человека оказалось записано больше одной карты,
        // можешь объединить в человека и привязать к нему эти карты». В
        // кадровой выгрузке СКУД таких людей семь плюс один, попавший в два
        // файла с разными номерами.
        //
        // У турникета это ничего не усложняет: `AccessCardResolver` идёт от
        // номера карты к человеку и к его действующему пропуску, а не от
        // человека к «его карте». Поэтому открывают **все** карты человека,
        // и закреплено это `SeveralCardsPerPersonTest`.

        $old = $card->only(['person_id', 'status', 'issued_at', 'returned_at']);

        DB::transaction(function () use ($card, $person, $note): void {
            // Хвост от прошлой жизни карты: незакрытая строка при выдаче другому
            // человеку. Закрываем заменой, иначе журнал покажет карту у двоих.
            $this->closeOpenIssue($card, RfidCardIssue::REASON_REPLACED);

            $card->forceFill([
                'person_id' => $person->id,
                'status' => RfidCard::STATUS_ISSUED,
                'issued_at' => now(),
                'returned_at' => null,
                'note' => $note ?? $card->note,
            ])->save();

            RfidCardIssue::create([
                'rfid_card_id' => $card->id,
                'person_id' => $person->id,
                'issued_at' => now(),
                'issued_by_user_id' => Auth::id(),
                'note' => $note,
            ]);
        });

        AuditLogService::log('rfid', 'card_issued', $card, $old, $card->only(['person_id', 'status', 'issued_at']), personId: $person->id);

        return $card->fresh(['person', 'currentIssue']);
    }

    public function accept(RfidCard $card, ?string $note = null): RfidCard
    {
        $old = $card->only(['person_id', 'status', 'issued_at', 'returned_at']);

        DB::transaction(function () use ($card, $note): void {
            $this->closeOpenIssue($card, RfidCardIssue::REASON_RETURNED, $note);

            // Владелец снимается: `person_id` на карте означает «у кого она
            // сейчас», а не «у кого была». Пока прежний владелец оставался,
            // сданная карта в реестре числилась за ним, и выглядело это так,
            // будто её не отвязали. Кто держал её раньше — в журнале.
            $card->forceFill([
                'person_id' => null,
                'status' => RfidCard::STATUS_STOCK,
                'returned_at' => now(),
                'note' => $note ?? $card->note,
            ])->save();
        });

        AuditLogService::log('rfid', 'card_accepted', $card, $old, $card->only(['person_id', 'status', 'returned_at']));

        return $card->fresh(['person', 'currentIssue']);
    }

    /**
     * Отвязать карту от человека, не принимая её физически.
     *
     * Нужно, когда карта на руках не окажется: человек уволился или отчислился,
     * карта осталась у него или пропала. Выдача закрывается с причиной, карта
     * перестаёт числиться за человеком — и её можно выдать другому.
     *
     * Состояние карты при этом не выдумывается: выданная становится свободной,
     * а утерянная или списанная такой и остаётся. «Утеряна» — свойство карты,
     * «за кем числится» — отдельная величина.
     */
    public function release(RfidCard $card, string $reason = RfidCardIssue::REASON_RETURNED, ?string $note = null): RfidCard
    {
        if (! in_array($reason, RfidCardIssue::REASONS, true)) {
            throw ValidationException::withMessages(['reason' => 'Неизвестная причина закрытия выдачи.']);
        }

        if ($card->person_id === null && $card->status !== RfidCard::STATUS_ISSUED) {
            throw ValidationException::withMessages([
                'card' => 'Карта ни за кем не числится — отвязывать нечего.',
            ]);
        }

        $old = $card->only(['person_id', 'status']);

        DB::transaction(function () use ($card, $reason, $note): void {
            $this->closeOpenIssue($card, $reason, $note);

            $card->forceFill([
                'person_id' => null,
                'status' => $card->status === RfidCard::STATUS_ISSUED ? RfidCard::STATUS_STOCK : $card->status,
                'returned_at' => now(),
                'note' => $note ?? $card->note,
            ])->save();
        });

        AuditLogService::log('rfid', 'card_released', $card, $old, $card->only(['person_id', 'status']));

        return $card->fresh(['person', 'currentIssue']);
    }

    /**
     * Удалить карту насовсем.
     *
     * Нужно для честной ошибки: номер набрали руками и промахнулись, карта
     * завелась не та. Такую запись надо стирать, а не списывать — списанная
     * останется в реестре и будет путать.
     *
     * Одно ограничение: карту, которая **сейчас у человека на руках**, удалить
     * нельзя. Она существует физически, по ней ходят, и стереть её значило бы
     * потерять след живой карты. Сначала примите или отвяжите.
     *
     * Строки журнала уходят вместе с картой — иначе они остались бы висеть без
     * карты. След при этом не пропадает: удаление пишется в аудит вместе с
     * номером и тем, у кого карта была.
     */
    public function delete(RfidCard $card): void
    {
        if ($card->status === RfidCard::STATUS_ISSUED) {
            throw ValidationException::withMessages([
                'card' => 'Карта сейчас на руках у человека. Сначала примите её или отвяжите — потом можно удалять.',
            ]);
        }

        $issues = RfidCardIssue::query()->where('rfid_card_id', $card->id)->count();

        $old = $card->only(['uid', 'label', 'status', 'person_id']) + ['issues' => $issues];

        AuditLogService::log('rfid', 'card_deleted', $card, $old, null);

        $card->delete();
    }

    /**
     * Сменить состояние карты: утеряна, заблокирована, списана или снова на складе.
     *
     * Утеря и списание закрывают выдачу — человек освобождается под новую карту.
     * Блокировка не закрывает: карта осталась на руках, закрыт только проход.
     */
    public function changeStatus(RfidCard $card, string $status, ?string $note = null, ?string $reason = null): RfidCard
    {
        if (! in_array($status, RfidCard::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Неизвестное состояние карты.']);
        }

        if ($status === RfidCard::STATUS_ISSUED) {
            throw ValidationException::withMessages([
                'status' => 'Выдать карту можно только выдачей — тогда портал запишет, кому и когда.',
            ]);
        }

        if ($reason !== null && ! in_array($reason, RfidCardIssue::REASONS, true)) {
            throw ValidationException::withMessages(['reason' => 'Неизвестная причина закрытия выдачи.']);
        }

        $old = $card->only(['status', 'note']);

        DB::transaction(function () use ($card, $status, $note, $reason): void {
            $closing = match ($status) {
                RfidCard::STATUS_LOST => RfidCardIssue::REASON_LOST,
                RfidCard::STATUS_WRITTEN_OFF => RfidCardIssue::REASON_DAMAGED,
                RfidCard::STATUS_STOCK => RfidCardIssue::REASON_RETURNED,
                default => null,
            };

            if ($closing !== null) {
                $this->closeOpenIssue($card, $reason ?? $closing, $note);
            }

            $card->forceFill([
                'status' => $status,
                // Выдача закрылась — значит, карта больше ни за кем не числится.
                // Кто держал её и почему она ушла, сказано в журнале.
                'person_id' => $closing === null ? $card->person_id : null,
                'note' => $note ?? $card->note,
            ])->save();
        });

        AuditLogService::log('rfid', 'card_status_changed', $card, $old, $card->only(['status', 'note']));

        return $card->fresh(['person', 'currentIssue']);
    }

    /** Закрыть открытую выдачу карты, если она есть. */
    private function closeOpenIssue(RfidCard $card, string $reason, ?string $note = null): void
    {
        $open = RfidCardIssue::query()
            ->where('rfid_card_id', $card->id)
            ->whereNull('returned_at')
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->first();

        if ($open === null) {
            return;
        }

        $open->forceFill([
            'returned_at' => now(),
            'accepted_by_user_id' => Auth::id(),
            'close_reason' => $reason,
            'note' => $note ?? $open->note,
        ])->save();
    }
}
