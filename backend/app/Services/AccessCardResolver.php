<?php

namespace App\Services;

use App\Models\DigitalIdentity;
use App\Models\RfidCard;
use App\Support\Rfid\CardNumber;

/**
 * Кого пускать по номеру RFID-карты.
 *
 * Карта — не второй пропуск, а второй способ предъявить тот же самый. Поэтому
 * разбор кончается **цифровым пропуском человека**, а не собственной сущностью
 * карты: дальше проходная работает ровно так же, как с QR.
 *
 * Иначе сломалось бы главное. Направление прохода и присутствие в здании
 * считаются по `digital_identity_id`: заведи карта свой пропуск — и человек,
 * вошедший по QR и вышедший по карте, оба раза «вошёл бы», а в списке «кто в
 * здании» остался бы навсегда.
 *
 * Отказ здесь — это причина словами, а не исключение: на проходной отказ обязан
 * попасть в журнал и на экран охранника, а не остаться ошибкой запроса.
 */
class AccessCardResolver
{
    /**
     * @return array{uid: ?string, identity: ?DigitalIdentity, reason: ?string}
     */
    public function resolve(string $raw): array
    {
        $uid = CardNumber::tryNormalize($raw);

        if ($uid === null) {
            return $this->deny(null, 'Код не распознан. Это не QR-пропуск и не номер карты.');
        }

        $card = RfidCard::query()->with('person')->firstWhere('uid', $uid);

        if ($card === null) {
            return $this->deny($uid, "Карта {$uid} не зарегистрирована.");
        }

        $refusal = match ($card->status) {
            RfidCard::STATUS_ISSUED => null,
            RfidCard::STATUS_STOCK => "Карта {$uid} никому не выдана.",
            RfidCard::STATUS_BLOCKED => "Карта {$uid} заблокирована.",
            RfidCard::STATUS_LOST => "Карта {$uid} числится утерянной.",
            RfidCard::STATUS_WRITTEN_OFF => "Карта {$uid} списана.",
            default => "Состояние карты {$uid} не разрешает проход.",
        };

        if ($refusal !== null) {
            return $this->deny($uid, $refusal);
        }

        if ($card->person_id === null) {
            return $this->deny($uid, "Карта {$uid} ни за кем не числится.");
        }

        $identity = $this->activeIdentity($card->person_id);

        if ($identity === null) {
            // Карта на руках, а пропуска нет: так бывает, если пропуск отозвали
            // отдельно от карты. Пускать нельзя, но и молчать нельзя — иначе на
            // проходной это выглядит как «карта не читается».
            return $this->deny($uid, "У владельца карты {$uid} нет действующего пропуска.");
        }

        // Проход по карте идёт своим путём и мимо `scanResult`, поэтому
        // проверка владельца нужна и здесь: иначе карта человека, стёртого из
        // системы, продолжала бы открывать турникет.
        if (! $identity->ownerExists()) {
            return $this->deny($uid, "Владелец карты {$uid} удалён из системы.");
        }

        return ['uid' => $uid, 'identity' => $identity, 'reason' => null];
    }

    /** Действующий цифровой пропуск человека. */
    private function activeIdentity(int $personId): ?DigitalIdentity
    {
        return DigitalIdentity::query()
            ->where('person_id', $personId)
            ->where('status', DigitalIdentity::STATUS_ACTIVE)
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{uid: ?string, identity: null, reason: string}
     */
    private function deny(?string $uid, string $reason): array
    {
        return ['uid' => $uid, 'identity' => null, 'reason' => $reason];
    }
}
