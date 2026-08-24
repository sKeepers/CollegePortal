<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGiaDecisionsRequest;
use App\Http\Requests\StoreGiaProtocolRequest;
use App\Models\GiaProtocol;
use App\Models\Group;
use App\Services\GiaProtocolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Протоколы ГИА.
 *
 * Смотреть их может тот, кто видит выпуск; вести — тот, у кого есть право на протоколы.
 * Подписанный протокол не правится: документ, на который ссылается диплом, задним числом
 * не меняют.
 */
class GiaProtocolController extends Controller
{
    public function __construct(private readonly GiaProtocolService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $protocols = GiaProtocol::query()
            ->with(['group', 'educationProgram'])
            ->withCount('decisions')
            ->when($request->string('academic_year')->toString(), fn ($query, string $year) => $query->where('academic_year', $year))
            ->when($request->integer('group_id'), fn ($query, int $groupId) => $query->where('group_id', $groupId))
            ->orderByDesc('protocol_date')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $protocols]);
    }

    public function show(GiaProtocol $giaProtocol): JsonResponse
    {
        return response()->json([
            'data' => [
                'protocol' => $giaProtocol->load(['group', 'educationProgram']),
                'decisions' => $this->service->sheet($giaProtocol),
            ],
        ]);
    }

    public function store(StoreGiaProtocolRequest $request): JsonResponse
    {
        $protocol = GiaProtocol::create($this->payload($request->validated(), $request));

        return response()->json(['data' => $protocol], Response::HTTP_CREATED);
    }

    public function update(StoreGiaProtocolRequest $request, GiaProtocol $giaProtocol): JsonResponse
    {
        $this->assertNotApproved($giaProtocol);
        $giaProtocol->update($this->payload($request->validated(), $request));

        return response()->json(['data' => $giaProtocol->fresh()]);
    }

    public function destroy(GiaProtocol $giaProtocol): Response
    {
        $this->assertNotApproved($giaProtocol);
        $giaProtocol->delete();

        return response()->noContent();
    }

    public function decisions(GiaProtocol $giaProtocol): JsonResponse
    {
        return response()->json(['data' => $this->service->sheet($giaProtocol)]);
    }

    public function storeDecisions(StoreGiaDecisionsRequest $request, GiaProtocol $giaProtocol): JsonResponse
    {
        $this->assertNotApproved($giaProtocol);

        return response()->json([
            'data' => $this->service->saveDecisions($giaProtocol, $request->validated()['decisions']),
        ]);
    }

    /**
     * Утверждённый протокол не правится.
     *
     * На него ссылается диплом, а диплом выдан человеку на руки. Отказ называет причину:
     * молчаливый 403 в журнале уже стоил захода.
     */
    private function assertNotApproved(GiaProtocol $protocol): void
    {
        abort_if(
            $protocol->status === 'approved',
            Response::HTTP_CONFLICT,
            'Протокол утверждён и больше не правится. На него ссылаются дипломы; чтобы изменить решение, нужен новый протокол.',
        );
    }

    /**
     * Название группы записывается вместе со ссылкой: протокол — документ, и читаться он
     * обязан сам по себе, в том числе когда группы уже нет.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function payload(array $data, Request $request): array
    {
        if (! empty($data['group_id'])) {
            $data['group_name'] = Group::query()->whereKey($data['group_id'])->value('name');
        }

        return $data + ['created_by' => $request->user()?->id];
    }
}
