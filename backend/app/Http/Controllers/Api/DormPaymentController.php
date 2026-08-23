<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DormPaymentResource;
use App\Models\DormPayment;
use App\Models\Student;
use App\Services\DormPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Оплата проживания. Ведёт комендант.
 *
 * Главный экран — сводка: по какое число закрыт каждый проживающий и на сколько
 * просрочил. Список самих отметок нужен реже, когда разбираются с конкретным
 * человеком, поэтому он отдельным запросом и с обязательным студентом.
 */
class DormPaymentController extends Controller
{
    public function __construct(private readonly DormPaymentService $payments)
    {
    }

    /** Сводка по проживающим: кто по какое число закрыт. */
    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'on' => ['nullable', 'date'],
        ], [
            'on.date' => 'Дата, на которую считать, не распознана.',
        ]);

        return response()->json(['data' => $this->payments->summary($data['on'] ?? null)->all()]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ], [
            'student_id.required' => 'Выберите студента: отметки об оплате смотрят по человеку.',
        ]);

        $payments = DormPayment::query()
            ->with(['createdBy', 'supersededBy'])
            ->where('student_id', $filters['student_id'])
            ->orderByDesc('paid_through')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 100);

        return DormPaymentResource::collection($payments);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'paid_through' => ['required', 'date'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'paid_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'student_id.required' => 'Выберите студента.',
            'paid_through.required' => 'Укажите, по какое число оплачено.',
        ]);

        // Происхождение отсюда всегда ручное: строки из 1С приходят обменом, а
        // не с экрана. Иначе ручная отметка смогла бы притвориться победившей.
        $payment = $this->payments->record(
            Student::query()->findOrFail($data['student_id']),
            $data['paid_through'],
            isset($data['amount']) ? (float) $data['amount'] : null,
            $data['paid_at'] ?? null,
            DormPayment::ORIGIN_MANUAL,
            null,
            $data['note'] ?? null,
        );

        return (new DormPaymentResource($payment->load('createdBy')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
