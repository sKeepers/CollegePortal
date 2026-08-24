<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignDiplomaBlankRequest;
use App\Http\Requests\ReceiveDiplomaBlankBatchRequest;
use App\Http\Requests\SpoilDiplomaBlankRequest;
use App\Http\Requests\WriteOffDiplomaBlankRequest;
use App\Http\Resources\DiplomaBlankBatchResource;
use App\Http\Resources\DiplomaBlankResource;
use App\Models\DiplomaBlank;
use App\Models\DiplomaBlankBatch;
use App\Models\Graduate;
use App\Services\Graduation\DiplomaBlankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Бланки строгой отчётности.
 *
 * Маршрута на удаление здесь нет и не будет: испорченный бланк отмечается
 * испорченным и списывается актом, а не стирается. Модели запрещают удаление и
 * со своей стороны — см. `DiplomaBlank`.
 */
class DiplomaBlankController extends Controller
{
    public function __construct(private readonly DiplomaBlankService $blanks)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $blanks = DiplomaBlank::query()
            ->with(['batch', 'graduate.student'])
            ->when($request->string('kind')->toString(), fn ($query, string $kind) => $query->where('kind', $kind))
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->string('series')->toString(), fn ($query, string $series) => $query->where('series', $series))
            ->when($request->integer('graduate_id'), fn ($query, int $id) => $query->where('graduate_id', $id))
            ->when($request->string('number')->toString(), fn ($query, string $number) => $query->where('number', 'like', '%'.$number.'%'))
            ->orderBy('kind')
            ->orderBy('series')
            ->orderBy('number')
            ->paginate(100);

        return DiplomaBlankResource::collection($blanks);
    }

    public function show(DiplomaBlank $diplomaBlank): DiplomaBlankResource
    {
        return new DiplomaBlankResource($diplomaBlank->load(['batch', 'graduate.student', 'events.user', 'events.graduate.student']));
    }

    /** Остаток по видам и сериям: сколько в наличии, сколько выдано, сколько испорчено. */
    public function balance(): JsonResponse
    {
        return response()->json(['data' => $this->blanks->balance()]);
    }

    public function batches(): AnonymousResourceCollection
    {
        $batches = DiplomaBlankBatch::query()
            ->withCount('blanks')
            ->with('receivedBy')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate(50);

        return DiplomaBlankBatchResource::collection($batches);
    }

    /** Приход партии: диапазон номеров разворачивается в отдельные бланки. */
    public function receive(ReceiveDiplomaBlankBatchRequest $request): JsonResponse
    {
        $batch = $this->blanks->receive($request->validated(), $this->userId($request));

        return (new DiplomaBlankBatchResource($batch->loadCount('blanks')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function assign(AssignDiplomaBlankRequest $request, DiplomaBlank $diplomaBlank): DiplomaBlankResource
    {
        $graduate = Graduate::findOrFail($request->integer('graduate_id'));

        $this->blanks->assign($diplomaBlank, $graduate, $this->userId($request), $request->input('note'));

        return new DiplomaBlankResource($diplomaBlank->fresh(['batch', 'graduate.student', 'events']));
    }

    public function release(Request $request, DiplomaBlank $diplomaBlank): DiplomaBlankResource
    {
        $this->blanks->release($diplomaBlank, $this->userId($request), $request->input('reason'));

        return new DiplomaBlankResource($diplomaBlank->fresh(['batch', 'graduate.student', 'events']));
    }

    public function issue(Request $request, DiplomaBlank $diplomaBlank): DiplomaBlankResource
    {
        $this->blanks->issue($diplomaBlank, $request->input('issued_at'), $this->userId($request));

        return new DiplomaBlankResource($diplomaBlank->fresh(['batch', 'graduate.student', 'events']));
    }

    public function spoil(SpoilDiplomaBlankRequest $request, DiplomaBlank $diplomaBlank): DiplomaBlankResource
    {
        $this->blanks->spoil($diplomaBlank, $request->string('reason')->toString(), $this->userId($request));

        return new DiplomaBlankResource($diplomaBlank->fresh(['batch', 'graduate.student', 'events']));
    }

    public function writeOff(WriteOffDiplomaBlankRequest $request, DiplomaBlank $diplomaBlank): DiplomaBlankResource
    {
        $this->blanks->writeOff(
            $diplomaBlank,
            $request->string('act_number')->toString(),
            $request->input('reason'),
            $this->userId($request),
        );

        return new DiplomaBlankResource($diplomaBlank->fresh(['batch', 'graduate.student', 'events']));
    }

    private function userId(Request $request): ?int
    {
        return $request->user()?->id;
    }
}
