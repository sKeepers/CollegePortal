<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeletionRequest;
use App\Services\Trash\DeletionRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use App\Support\Http\PageSize;

/**
 * Заявки на удаление и корзина.
 *
 * Пометить карточку может тот, кто её ведёт; решает и чистит корзину только
 * администратор.
 */
class DeletionRequestController extends Controller
{
    public function __construct(private readonly DeletionRequestService $service)
    {
    }

    /**
     * Что будет удалено вместе с карточкой и что этому мешает.
     *
     * Спрашивается до пометки: удалять молча нельзя, связанные записи надо
     * показать и предложить снять вместе — а то, что снять нельзя, назвать.
     */
    public function dependents(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(array_keys(DeletionRequestService::SUBJECTS))],
            'subject_id' => ['required', 'integer'],
        ]);

        return response()->json(['data' => $this->service->dependents(
            $data['subject_type'],
            (int) $data['subject_id'],
        )]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(array_keys(DeletionRequestService::SUBJECTS))],
            'subject_id' => ['required', 'integer'],
            // Причина обязательна: администратор проверяет заявку, а проверять
            // нечего, если не сказано, что не так с карточкой.
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $created = $this->service->create(
            $data['subject_type'],
            (int) $data['subject_id'],
            $data['reason'],
            $request->user(),
        );

        return response()->json(['data' => $this->present($created)], Response::HTTP_CREATED);
    }

    public function pending(): JsonResponse
    {
        $requests = DeletionRequest::query()
            ->pending()
            ->with(['requestedBy', 'subject'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (DeletionRequest $item): array => $this->present($item));

        return response()->json(['data' => $requests]);
    }

    public function index(Request $request): JsonResponse
    {
        $requests = DeletionRequest::query()
            ->with(['requestedBy', 'reviewedBy', 'subject'])
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(PageSize::from($request, 50))
            ->through(fn (DeletionRequest $item): array => $this->present($item));

        return response()->json($requests);
    }

    public function approve(DeletionRequest $deletionRequest, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->service->approve($deletionRequest, $request->user()))]);
    }

    public function reject(DeletionRequest $deletionRequest, Request $request): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);

        return response()->json([
            'data' => $this->present($this->service->reject($deletionRequest, $request->user(), $data['comment'] ?? null)),
        ]);
    }

    public function trash(): JsonResponse
    {
        return response()->json(['data' => $this->service->trash()]);
    }

    public function restore(string $type, int $id): JsonResponse
    {
        $this->service->restore($type, $id);

        return response()->json(['data' => ['type' => $type, 'id' => $id, 'restored' => true]]);
    }

    public function purge(string $type, int $id): JsonResponse
    {
        $this->service->purge($type, $id);

        return response()->json(['data' => ['type' => $type, 'id' => $id, 'purged' => true]]);
    }

    /** @return array<string, mixed> */
    private function present(DeletionRequest $request): array
    {
        return [
            'id' => $request->id,
            'subject_type' => array_search($request->subject_type, DeletionRequestService::SUBJECTS, true) ?: $request->subject_type,
            'subject_id' => $request->subject_id,
            'subject_label' => $request->subject_label,
            'subject_exists' => $request->subject !== null,
            'reason' => $request->reason,
            'status' => $request->status,
            'requested_by' => $request->requestedBy?->name,
            'reviewed_by' => $request->reviewedBy?->name,
            'reviewed_at' => $request->reviewed_at?->toISOString(),
            'review_comment' => $request->review_comment,
            'created_at' => $request->created_at?->toISOString(),
        ];
    }
}
