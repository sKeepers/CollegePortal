<?php

namespace App\Services\Import;

use App\Models\Person;
use App\Models\RfidCard;
use App\Models\RfidCardIssue;
use App\Support\Rfid\CardNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Загрузка журнала выдачи RFID-карт.
 *
 * Нужна для переноса того, что велось до портала: бумажной тетради коменданта
 * или выгрузки из старой СКУД. Строка — это одна выдача: кому, когда, какая
 * карта и чем закончилось.
 *
 * Человека загрузка **не заводит**. Журнал выдач — не место, где появляются
 * люди: строка с незнакомым ФИО завела бы двойника, и его потом пришлось бы
 * сводить руками. Такая строка честно падает в ошибки.
 *
 * Карту, наоборот, заводит: карта — это номер, и никакой другой карты с таким
 * номером быть не может.
 */
class RfidCardIssueImportHandler extends AbstractImportHandler
{
    /** Причины принимаются и кодом, и по-русски — тетрадь пишут словами. */
    private const REASONS = [
        'сдана' => RfidCardIssue::REASON_RETURNED,
        'вернул' => RfidCardIssue::REASON_RETURNED,
        'возврат' => RfidCardIssue::REASON_RETURNED,
        'утеряна' => RfidCardIssue::REASON_LOST,
        'потеряна' => RfidCardIssue::REASON_LOST,
        'испорчена' => RfidCardIssue::REASON_DAMAGED,
        'сломана' => RfidCardIssue::REASON_DAMAGED,
        'заменена' => RfidCardIssue::REASON_REPLACED,
        'выбыл' => RfidCardIssue::REASON_LEFT,
        'уволен' => RfidCardIssue::REASON_LEFT,
        'отчислен' => RfidCardIssue::REASON_LEFT,
    ];

    public const TYPE = 'rfid_card_issues';

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return 'Журнал выдачи RFID-карт';
    }

    public function modelClass(): string
    {
        return RfidCardIssue::class;
    }

    public function keyFields(): array
    {
        return ['card_uid', 'last_name', 'first_name', 'issued_at'];
    }

    public function fields(): array
    {
        return [
            'card_uid' => ['label' => 'Номер карты', 'required' => true, 'aliases' => ['номер карты', 'карта', 'card_uid', 'uid']],
            'last_name' => ['label' => 'Фамилия', 'required' => true, 'aliases' => ['фамилия', 'last_name']],
            'first_name' => ['label' => 'Имя', 'required' => true, 'aliases' => ['имя', 'first_name']],
            'middle_name' => ['label' => 'Отчество', 'required' => false, 'aliases' => ['отчество', 'middle_name']],
            'issued_at' => ['label' => 'Выдана', 'required' => true, 'aliases' => ['выдана', 'дата выдачи', 'issued_at']],
            'returned_at' => ['label' => 'Закрыта', 'required' => false, 'aliases' => ['закрыта', 'дата возврата', 'сдана', 'returned_at']],
            'close_reason' => ['label' => 'Причина', 'required' => false, 'aliases' => ['причина', 'close_reason']],
            'note' => ['label' => 'Примечание', 'required' => false, 'aliases' => ['примечание', 'note']],
        ];
    }

    public function templateHeaders(): array
    {
        return ['Номер карты', 'Фамилия', 'Имя', 'Отчество', 'Выдана', 'Закрыта', 'Причина', 'Примечание'];
    }

    public function templateExample(): array
    {
        return ['0008327739', 'Иванов', 'Иван', 'Иванович', '17.08.2026', '20.08.2026', 'Сдана', 'Перенесено из тетради'];
    }

    public function prepare(array $data): array
    {
        $data['issued_at'] = $this->normalizeDate($data['issued_at'] ?? null);
        $data['returned_at'] = $this->normalizeDate($data['returned_at'] ?? null);

        $reason = trim((string) ($data['close_reason'] ?? ''));

        if ($reason !== '') {
            $data['close_reason'] = self::REASONS[mb_strtolower($reason)] ?? $reason;
        } else {
            $data['close_reason'] = null;
        }

        return $data;
    }

    public function rules(): array
    {
        return [
            'card_uid' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'issued_at' => ['required', 'date'],
            'returned_at' => ['nullable', 'date'],
            'close_reason' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function businessValidationErrors(array $data): array
    {
        $errors = [];

        try {
            CardNumber::normalize((string) ($data['card_uid'] ?? ''));
        } catch (ValidationException $exception) {
            $errors[] = $exception->validator->errors()->first('uid');
        }

        if ($this->findPerson($data) === null) {
            $errors[] = 'Человек с таким ФИО в портале не найден. Журнал выдач людей не заводит — заведите человека и повторите.';
        }

        $reason = $data['close_reason'] ?? null;

        if ($reason !== null && ! in_array($reason, RfidCardIssue::REASONS, true)) {
            $errors[] = 'Неизвестная причина закрытия выдачи: '.$reason;
        }

        if (($data['returned_at'] ?? null) !== null && ($data['issued_at'] ?? null) !== null
            && strtotime($data['returned_at']) < strtotime($data['issued_at'])) {
            $errors[] = 'Дата закрытия раньше даты выдачи.';
        }

        return $errors;
    }

    public function findExisting(array $data): ?Model
    {
        $person = $this->findPerson($data);

        if ($person === null) {
            return null;
        }

        $card = RfidCard::query()->firstWhere('uid', $this->uid($data));

        if ($card === null) {
            return null;
        }

        return RfidCardIssue::query()
            ->where('rfid_card_id', $card->id)
            ->where('person_id', $person->id)
            ->whereDate('issued_at', $data['issued_at'])
            ->first();
    }

    public function import(array $data, string $mode): string
    {
        $existing = $this->findExisting($data);

        if ($existing !== null) {
            if ($mode === self::MODE_UPDATE) {
                $existing->update([
                    'returned_at' => $data['returned_at'] ?? null,
                    'close_reason' => $data['close_reason'] ?? null,
                    'note' => $data['note'] ?? $existing->note,
                ]);

                return 'updated';
            }

            if ($mode === self::MODE_SKIP_DUPLICATES) {
                return $this->skipped(self::SKIP_DUPLICATE);
            }

            throw new RuntimeException('Такая выдача уже есть в журнале.');
        }

        if ($mode === self::MODE_UPDATE) {
            return $this->skipped(self::SKIP_NOT_FOUND);
        }

        $person = $this->findPerson($data);

        if ($person === null) {
            throw new RuntimeException('Человек с таким ФИО в портале не найден.');
        }

        $card = $this->card($data);
        $open = ($data['returned_at'] ?? null) === null;

        RfidCardIssue::create([
            'rfid_card_id' => $card->id,
            'person_id' => $person->id,
            'issued_at' => $data['issued_at'],
            'returned_at' => $data['returned_at'] ?? null,
            'close_reason' => $data['close_reason'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        // Снимок в самой карте держим согласованным с журналом: открытая
        // выдача означает, что карта сейчас на руках.
        if ($open) {
            $card->forceFill([
                'person_id' => $person->id,
                'status' => RfidCard::STATUS_ISSUED,
                'issued_at' => $data['issued_at'],
                'returned_at' => null,
            ])->save();
        }

        return 'created';
    }

    private function card(array $data): RfidCard
    {
        $uid = $this->uid($data);
        $card = RfidCard::query()->firstWhere('uid', $uid);

        if ($card !== null) {
            return $card;
        }

        // insertOrIgnore, а не создание с перехватом исключения: на PostgreSQL
        // упавший INSERT отравляет транзакцию целиком.
        RfidCard::query()->insertOrIgnore([
            'uid' => $uid,
            'uid_raw' => trim((string) $data['card_uid']),
            'status' => RfidCard::STATUS_STOCK,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $card = RfidCard::query()->firstWhere('uid', $uid);

        if ($card === null) {
            throw new RuntimeException('Не удалось завести карту с номером '.$uid);
        }

        return $card;
    }

    private function uid(array $data): string
    {
        return CardNumber::normalize((string) ($data['card_uid'] ?? ''));
    }

    private function findPerson(array $data): ?Person
    {
        $query = Person::query()
            ->whereRaw('lower(last_name) = ?', [mb_strtolower(trim((string) ($data['last_name'] ?? '')))])
            ->whereRaw('lower(first_name) = ?', [mb_strtolower(trim((string) ($data['first_name'] ?? '')))]);

        $middle = trim((string) ($data['middle_name'] ?? ''));

        if ($middle !== '') {
            $query->whereRaw('lower(middle_name) = ?', [mb_strtolower($middle)]);
        }

        // Двух тёзок различить нечем, и гадать нельзя: строка уйдёт в ошибки.
        $found = $query->limit(2)->get();

        return $found->count() === 1 ? $found->first() : null;
    }
}
