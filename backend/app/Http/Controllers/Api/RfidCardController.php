<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RfidCardResource;
use App\Models\Person;
use App\Models\RfidCard;
use App\Services\AuditLogService;
use App\Services\RfidCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Учёт RFID-карт. Ведёт комендант.
 *
 * Экран заменяет тетрадь: какие карты есть, в каком они состоянии и у кого на
 * руках. Выдача и приём — отдельные действия, а не правка поля: портал должен
 * записать, кому и когда, иначе учёт не отличается от списка.
 */
class RfidCardController extends Controller
{
    public function __construct(private readonly RfidCardService $cards)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(RfidCard::STATUSES)],
            'person_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $operator = RfidCard::query()->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $cards = RfidCard::query()
            ->with('person')
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['person_id'] ?? null, fn ($query, int $id) => $query->where('person_id', $id))
            ->when($filters['search'] ?? null, function ($query, string $search) use ($operator): void {
                // Ищут и по номеру карты, и по фамилии владельца: комендант
                // одинаково часто держит в руках карту и слышит фамилию.
                $query->where(function ($query) use ($operator, $search): void {
                    $query->where('uid', $operator, "%{$search}%")
                        ->orWhere('label', $operator, "%{$search}%")
                        ->orWhereHas('person', fn ($person) => $person->where('last_name', $operator, "%{$search}%"));
                });
            })
            ->orderByRaw("case when status = 'issued' then 0 else 1 end")
            ->orderBy('uid')
            ->paginate($filters['per_page'] ?? 50);

        return RfidCardResource::collection($cards);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uid' => ['required', 'string', 'max:100', Rule::unique('rfid_cards', 'uid')],
            'label' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $card = RfidCard::create($data + ['status' => RfidCard::STATUS_STOCK]);

        AuditLogService::log('rfid', 'card_created', $card, null, $card->only(['uid', 'label', 'status']), $request);

        return (new RfidCardResource($card))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(Request $request, RfidCard $rfidCard): RfidCardResource
    {
        $data = $request->validate([
            'uid' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('rfid_cards', 'uid')->ignore($rfidCard->id)],
            'label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $old = $rfidCard->only(['uid', 'label', 'note']);
        $rfidCard->update($data);

        AuditLogService::log('rfid', 'card_updated', $rfidCard, $old, $rfidCard->only(['uid', 'label', 'note']), $request);

        return new RfidCardResource($rfidCard->load('person'));
    }

    public function issue(Request $request, RfidCard $rfidCard): RfidCardResource
    {
        $data = $request->validate([
            'person_id' => ['required', 'integer', 'exists:people,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $person = Person::query()->findOrFail($data['person_id']);

        return new RfidCardResource($this->cards->issue($rfidCard, $person, $data['note'] ?? null));
    }

    public function accept(Request $request, RfidCard $rfidCard): RfidCardResource
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return new RfidCardResource($this->cards->accept($rfidCard, $data['note'] ?? null));
    }

    public function status(Request $request, RfidCard $rfidCard): RfidCardResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(RfidCard::STATUSES)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return new RfidCardResource($this->cards->changeStatus($rfidCard, $data['status'], $data['note'] ?? null));
    }
}
