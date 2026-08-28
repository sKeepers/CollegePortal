<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DormSocialRecordResource;
use App\Models\DormSocialRecord;
use App\Services\AuditLogService;
use App\Support\Http\PageSize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Социальный паспорт. Ведёт заместитель по воспитательной работе.
 *
 * Самые чувствительные данные во всём портале. Право на них выдано ровно одной
 * роли: ни комендант, ни куратор, ни директор их не видят.
 *
 * **Чтение пишется в аудит наравне с правкой.** В остальном портале аудит
 * фиксирует изменения; здесь этого мало — сам факт просмотра социального
 * паспорта есть событие, о котором должен остаться след. Это требование
 * разбора, а не перестраховка: спросят «кто это смотрел», и ответ должен быть.
 */
class DormSocialController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'student_id' => ['nullable', 'integer'],
            'category' => ['nullable', Rule::in(array_keys(DormSocialRecord::CATEGORIES))],
            'open' => ['nullable', 'boolean'],
        ]);

        $records = DormSocialRecord::query()
            ->with(['student.group', 'createdBy'])
            ->when($filters['student_id'] ?? null, fn ($query, int $id) => $query->where('student_id', $id))
            ->when($filters['category'] ?? null, fn ($query, string $category) => $query->where('category', $category))
            ->when(array_key_exists('open', $filters) && $filters['open'] !== null, function ($query) use ($filters): void {
                $filters['open'] ? $query->whereNull('closed_on') : $query->whereNotNull('closed_on');
            })
            ->orderByDesc('opened_on')
            ->orderByDesc('id')
            ->paginate(PageSize::from($request, 100));

        AuditLogService::log('dorm.social', 'viewed', null, null, [
            'user_id' => Auth::id(),
            'student_id' => $filters['student_id'] ?? null,
            'category' => $filters['category'] ?? null,
            'rows' => $records->total(),
        ], $request);

        return DormSocialRecordResource::collection($records);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'category' => ['required', Rule::in(array_keys(DormSocialRecord::CATEGORIES))],
            'details' => ['nullable', 'string', 'max:5000'],
            'opened_on' => ['required', 'date'],
            'closed_on' => ['nullable', 'date', 'after_or_equal:opened_on'],
        ], [
            'student_id.required' => 'Выберите студента.',
            'category.required' => 'Выберите категорию.',
            'opened_on.required' => 'Укажите, с какого числа сведения действуют.',
            'closed_on.after_or_equal' => 'Дата закрытия раньше даты открытия.',
        ]);

        $record = DormSocialRecord::create($data + ['created_by_user_id' => Auth::id()]);

        AuditLogService::log('dorm.social', 'recorded', $record, null, $record->only(['student_id', 'category', 'opened_on']), $request);

        return (new DormSocialRecordResource($record->load(['student.group', 'createdBy'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(Request $request, DormSocialRecord $dormSocialRecord): DormSocialRecordResource
    {
        $data = $request->validate([
            'details' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'closed_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:opened_on'],
        ]);

        $old = $dormSocialRecord->only(['details', 'closed_on']);
        $dormSocialRecord->update($data);

        AuditLogService::log('dorm.social', 'updated', $dormSocialRecord, $old, $dormSocialRecord->only(['details', 'closed_on']), $request);

        return new DormSocialRecordResource($dormSocialRecord->load(['student.group', 'createdBy']));
    }
}
