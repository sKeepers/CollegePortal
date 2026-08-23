<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DormIncidentResource;
use App\Models\DormIncident;
use App\Services\AuditLogService;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Происшествия в общежитии. Ведут обе роли.
 *
 * Записывается по горячим следам: обязательны только время и одна строка о том,
 * что случилось. Участники, подробности и меры дописываются потом — если
 * требовать их сразу, запись просто не появится, а появится она уже никогда.
 *
 * Участники — студенты, и список правится целиком: проще снять галочку, чем
 * искать, кого убрать по одному.
 */
class DormIncidentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'dorm_room_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:300'],
        ], [
            'from.date' => 'Дата «с» не распознана.',
            'to.date' => 'Дата «по» не распознана.',
        ]);

        $incidents = DormIncident::query()
            ->with(['room', 'createdBy', 'participants.group'])
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('happened_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('happened_at', '<=', $to.' 23:59:59'))
            ->when($filters['dorm_room_id'] ?? null, fn ($query, int $id) => $query->where('dorm_room_id', $id))
            ->orderByDesc('happened_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 100);

        return DormIncidentResource::collection($incidents);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $incident = DB::transaction(function () use ($data): DormIncident {
            $incident = DormIncident::create([
                'building_id' => (int) SettingService::value('dorm', 'building_id', 0) ?: null,
                'dorm_room_id' => $data['dorm_room_id'] ?? null,
                'happened_at' => $data['happened_at'],
                'summary' => $data['summary'],
                'description' => $data['description'] ?? null,
                'measures' => $data['measures'] ?? null,
                'created_by_user_id' => Auth::id(),
            ]);

            $this->syncParticipants($incident, $data['participants'] ?? []);

            return $incident;
        });

        AuditLogService::log('dorm', 'incident_recorded', $incident, null, $incident->only(['happened_at', 'summary']), $request);

        return (new DormIncidentResource($incident->load(['room', 'createdBy', 'participants.group'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(Request $request, DormIncident $dormIncident): DormIncidentResource
    {
        $data = $this->validated($request);
        $old = $dormIncident->only(['summary', 'description', 'measures']);

        DB::transaction(function () use ($dormIncident, $data): void {
            $dormIncident->update([
                'dorm_room_id' => $data['dorm_room_id'] ?? null,
                'happened_at' => $data['happened_at'],
                'summary' => $data['summary'],
                'description' => $data['description'] ?? null,
                'measures' => $data['measures'] ?? null,
            ]);

            $this->syncParticipants($dormIncident, $data['participants'] ?? []);
        });

        AuditLogService::log('dorm', 'incident_updated', $dormIncident, $old, $dormIncident->only(['summary', 'description', 'measures']), $request);

        return new DormIncidentResource($dormIncident->load(['room', 'createdBy', 'participants.group']));
    }

    private function syncParticipants(DormIncident $incident, array $participants): void
    {
        $incident->participants()->sync(
            collect($participants)->mapWithKeys(fn (int $studentId) => [$studentId => ['role' => 'participant']])->all(),
        );
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'happened_at' => ['required', 'date'],
            'summary' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'measures' => ['nullable', 'string', 'max:5000'],
            'dorm_room_id' => ['nullable', 'integer', 'exists:dorm_rooms,id'],
            'participants' => ['nullable', 'array'],
            'participants.*' => ['integer', 'exists:students,id'],
        ], [
            'happened_at.required' => 'Укажите, когда это произошло.',
            'summary.required' => 'Скажите в одну строку, что случилось. Подробности допишете потом.',
        ]);
    }
}
