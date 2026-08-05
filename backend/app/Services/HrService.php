<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Person;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HrService
{
    public function __construct(private readonly PersonService $personService) {}

    public function createEmployee(array $data): Employee
    {
        return DB::transaction(function () use ($data): Employee {
            $person = $this->resolvePerson($data);
            $employee = Employee::create($this->employeePayload($data, $person));
            $this->syncTeacher($employee, $person);
            return $employee->fresh(['person', 'primaryDepartment', 'primaryPosition', 'assignments.department', 'assignments.position', 'statusPeriods', 'teacher']);
        });
    }

    public function updateEmployee(Employee $employee, array $data): Employee
    {
        $employee->update($this->employeePayload($data, $employee->person, true));
        $this->syncTeacher($employee, $employee->person);
        return $employee->fresh(['person', 'primaryDepartment', 'primaryPosition', 'assignments.department', 'assignments.position', 'statusPeriods', 'teacher']);
    }

    public function teacherAvailability(?int $teacherId, ?string $date): ?array
    {
        if (! $teacherId) { return null; }
        $teacher = Teacher::query()->whereKey($teacherId)->first();
        if (! $teacher?->person_id) { return null; }
        $employee = Employee::query()->with('statusPeriods')->where('person_id', $teacher->person_id)->first();
        if (! $employee) { return null; }
        $status = $employee->statusOn($date);
        if (! in_array($status, Employee::UNAVAILABLE_STATUSES, true)) { return ['available' => true, 'status' => $status]; }
        return ['available' => false, 'status' => $status, 'warning' => 'Преподаватель недоступен по кадровым данным'];
    }

    private function resolvePerson(array $data): Person
    {
        if (! empty($data['person_id'])) { return Person::findOrFail($data['person_id']); }
        $personData = [
            'last_name' => $data['last_name'] ?? '', 'first_name' => $data['first_name'] ?? '', 'middle_name' => $data['middle_name'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null, 'snils' => $data['snils'] ?? null, 'status' => 'active',
        ];
        $duplicates = $this->personService->findPossibleDuplicates($personData);
        if ($duplicates->count() > 1) { throw ValidationException::withMessages(['person_id' => 'Найдено несколько возможных Person. Выберите существующую запись вручную.']); }
        return $duplicates->first() ?: $this->personService->createPerson($personData);
    }

    private function employeePayload(array $data, Person $person, bool $updating = false): array
    {
        $payload = [
            'person_id' => $person->id,
            'employee_number' => $data['employee_number'] ?? null,
            'status' => $data['status'] ?? 'active',
            'employment_type' => $data['employment_type'] ?? 'full_time',
            'work_schedule_code' => $data['work_schedule_code'] ?? null,
            'hired_at' => $data['hired_at'] ?? null,
            'dismissed_at' => $data['dismissed_at'] ?? null,
            'primary_department_id' => $data['primary_department_id'] ?? null,
            'primary_position_id' => $data['primary_position_id'] ?? null,
            'workload_rate' => $data['workload_rate'] ?? null,
            'is_teacher' => (bool) ($data['is_teacher'] ?? false),
            'comment' => $data['comment'] ?? null,
        ];

        if (! $updating) {
            $payload['employee_number'] ??= $this->nextEmployeeNumber();

            return $payload;
        }

        return array_filter($payload, fn (string $key): bool => array_key_exists($key, $data), ARRAY_FILTER_USE_KEY);
    }

    private function syncTeacher(Employee $employee, Person $person): void
    {
        if (! $employee->is_teacher) {
            return;
        }

        $teacher = Teacher::firstOrNew(['person_id' => $person->id]);
        $teacher->fill([
            'last_name' => $person->last_name,
            'first_name' => $person->first_name,
            'middle_name' => $person->middle_name,
            'phone' => $person->phone,
            'email' => $person->email,
            'position' => $employee->primaryPosition?->name,
            'department' => $employee->primaryDepartment?->name,
            'is_active' => $employee->status !== 'dismissed',
        ]);
        $teacher->save();
    }

    private function nextEmployeeNumber(): string
    {
        $next = ((int) Employee::query()->max('id')) + 1;

        return 'EMP-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
