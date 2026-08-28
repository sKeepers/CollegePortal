<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DormConductRecordResource;
use App\Models\DormConductRecord;
use App\Models\Student;
use App\Services\DormConductService;
use App\Support\Http\PageSize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Провинности. Ведёт заместитель по воспитательной работе.
 *
 * Коменданту этот раздел не виден вовсе — у него нет права `dorm.conduct.*`, и
 * это разграничение, а не недоделка. Студенту он не виден тем более: решение
 * владельца от 22.08.2026, и student-facing запроса здесь нет ни одного.
 *
 * Записи не удаляются: они гаснут через год. Правит запись только автор и
 * только в течение суток — дальше дополнением, чтобы история не переписывалась
 * задним числом.
 */
class DormConductController extends Controller
{
    public function __construct(private readonly DormConductService $conduct)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'student_id' => ['nullable', 'integer'],
            'active' => ['nullable', 'boolean'],
        ], [
            'active.boolean' => 'Признак действующей записи задаётся как «да» или «нет».',
        ]);

        $records = DormConductRecord::query()
            ->with(['student.group', 'createdBy', 'amendments.createdBy'])
            // Дополнения показываются при исходной записи, а не отдельными
            // строками: иначе список превращается в набор обрывков.
            ->whereNull('parent_id')
            ->when($filters['student_id'] ?? null, fn ($query, int $id) => $query->where('student_id', $id))
            ->when(array_key_exists('active', $filters) && $filters['active'] !== null, function ($query) use ($filters): void {
                $filters['active']
                    ? $query->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', now()->toDateString()))
                    : $query->whereDate('expires_on', '<', now()->toDateString());
            })
            ->orderByDesc('happened_on')
            ->orderByDesc('id')
            ->paginate(PageSize::from($request, 100));

        return DormConductRecordResource::collection($records);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'happened_on' => ['required', 'date'],
            'summary' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ], [
            'student_id.required' => 'Выберите студента.',
            'happened_on.required' => 'Укажите дату.',
            'summary.required' => 'Скажите в одну строку, что произошло.',
        ]);

        $record = $this->conduct->record(
            Student::query()->findOrFail($data['student_id']),
            $data['happened_on'],
            $data['summary'],
            $data['description'] ?? null,
        );

        return (new DormConductRecordResource($record->load(['student.group', 'createdBy'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(Request $request, DormConductRecord $dormConductRecord): DormConductRecordResource
    {
        $data = $request->validate([
            'summary' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $record = $this->conduct->update($dormConductRecord, $data['summary'], $data['description'] ?? null);

        return new DormConductRecordResource($record->load(['student.group', 'createdBy', 'amendments.createdBy']));
    }

    public function amend(Request $request, DormConductRecord $dormConductRecord): JsonResponse
    {
        $data = $request->validate([
            'summary' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ], [
            'summary.required' => 'Скажите в одну строку, что дополняете.',
        ]);

        $record = $this->conduct->amend($dormConductRecord, $data['summary'], $data['description'] ?? null);

        return (new DormConductRecordResource($record->load('createdBy')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
