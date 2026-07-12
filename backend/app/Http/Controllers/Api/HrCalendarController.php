<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeStatusPeriodResource;
use App\Models\Employee;
use App\Models\EmployeeStatusPeriod;
use App\Models\ScheduleEntry;
use App\Models\User;
use App\Services\HrAbsenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class HrCalendarController extends Controller
{
    public function __construct(private readonly HrAbsenceService $service)
    {
    }

    public function calendar(Request $request): JsonResponse
    {
        $filters = $this->scopedFilters($request);
        return response()->json(['data' => $this->service->calendar($filters)]);
    }

    public function previewPeriod(Request $request, Employee $employee): JsonResponse
    {
        $data = $this->validatePeriod($request);
        return response()->json($this->service->previewPeriod($employee->load(['person', 'teacher']), $data));
    }

    public function applyPeriod(Request $request, Employee $employee): EmployeeStatusPeriodResource
    {
        $data = $this->validatePeriod($request);
        return new EmployeeStatusPeriodResource($this->service->applyPeriod($employee->load(['person', 'teacher']), $data, $request->user()));
    }

    public function cancelPeriod(Request $request, EmployeeStatusPeriod $period): EmployeeStatusPeriodResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        return new EmployeeStatusPeriodResource($this->service->cancelPeriod($period->load('employee.teacher'), $request->user(), $data['reason'] ?? null));
    }

    public function affectedLessons(Request $request, EmployeeStatusPeriod $period): JsonResponse
    {
        $this->authorizeTeacherPeriod($request->user(), $period);
        return response()->json(['data' => $this->service->affectedLessons($period->load('employee.teacher'))]);
    }

    public function candidates(ScheduleEntry $scheduleEntry, Employee $employee): JsonResponse
    {
        return response()->json(['data' => $this->service->replacementCandidates($scheduleEntry, $employee)]);
    }

    public function replacementPreview(Request $request): JsonResponse
    {
        $data = $request->validate(['items' => ['required', 'array', 'min:1'], 'items.*.schedule_entry_id' => ['required', 'integer', 'exists:schedule_entries,id'], 'items.*.teacher_id' => ['required', 'integer', 'exists:teachers,id']]);
        return response()->json($this->service->replacementPreview($data['items']));
    }

    public function replacementApply(Request $request): JsonResponse
    {
        $data = $request->validate(['items' => ['required', 'array', 'min:1'], 'items.*.schedule_entry_id' => ['required', 'integer', 'exists:schedule_entries,id'], 'items.*.teacher_id' => ['required', 'integer', 'exists:teachers,id']]);
        return response()->json(['data' => $this->service->applyReplacements($data['items'], $request->user())]);
    }

    public function report(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->reportRows($request->query())]);
    }

    public function export(Request $request): Response
    {
        $rows = $this->service->reportRows($request->query());
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Сотрудник', 'Подразделение', 'Статус', 'Состояние периода', 'Дата начала', 'Дата окончания', 'Затронуто занятий'], ';');
        foreach ($rows as $row) {
            fputcsv($handle, [$row['employee_name'], $row['department'], $row['status'], $row['period_status'], $row['date_from'], $row['date_to'], $row['affected_lessons_count']], ';');
        }
        rewind($handle);
        return response(stream_get_contents($handle) ?: '', 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="hr_absences.csv"']);
    }

    private function scopedFilters(Request $request): array
    {
        $filters = $request->query();
        if ($this->isTeacherOnly($request->user())) {
            $employeeId = Employee::query()
                ->where('person_id', $request->user()->person_id)
                ->orWhereHas('teacher', fn ($query) => $query->where('user_id', $request->user()->id))
                ->value('id');
            $filters['employee_id'] = $employeeId ?: -1;
        }
        return $filters;
    }

    private function authorizeTeacherPeriod(User $user, EmployeeStatusPeriod $period): void
    {
        if (! $this->isTeacherOnly($user)) {
            return;
        }
        $allowed = Employee::query()
            ->whereKey($period->employee_id)
            ->where(fn ($query) => $query->where('person_id', $user->person_id)->orWhereHas('teacher', fn ($q) => $q->where('user_id', $user->id)))
            ->exists();
        abort_unless($allowed, 403);
    }

    private function isTeacherOnly(User $user): bool
    {
        return $user->hasRole('teacher') && ! ($user->hasRole('admin') || $user->hasRole('director') || $user->hasRole('deputy') || $user->hasRole('study') || $user->hasRole('academic_office') || $user->hasRole('hr'));
    }

    private function validatePeriod(Request $request): array
    {
        return $request->validate([
            'status' => ['required', Rule::in(['vacation', 'sick_leave', 'maternity_leave', 'business_trip', 'suspended', 'dismissed'])],
            'period_status' => ['nullable', Rule::in(['planned', 'active', 'completed', 'cancelled'])],
            'date_from' => ['required', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'reason' => ['nullable', 'string', 'max:255'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'document_date' => ['nullable', 'date'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'metadata' => ['nullable', 'array'],
        ]);
    }
}
