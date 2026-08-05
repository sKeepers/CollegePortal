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
            if (($data['is_teacher'] ?? false) && ! Teacher::where('person_id', $person->id)->exists()) {
                // HR marks teacher status only as a relationship hint; Teacher profile is still created explicitly in Teachers module.
            }
            return $employee->fresh(['person', 'primaryDepartment', 'primaryPosition', 'assignments.department', 'assignments.position', 'statusPeriods', 'teacher']);
        });
    }

    public function updateEmployee(Employee $employee, array $data): Employee
    {
        $employee->update($this->employeePayload($data + ['work_schedule_code' => $employee->work_schedule_code], $employee->person));
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
            'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null, 'snils' => $data['snils'] ?? null, 'status' => 'active',
        ];
        $duplicates = $this->personService->findPossibleDuplicates($personData);
        if ($duplicates->count() > 1) { throw ValidationException::withMessages(['person_id' => 'Найдено несколько возможных Person. Выберите существующую запись вручную.']); }
        return $duplicates->first() ?: $this->personService->createPerson($personData);
    }

    private function employeePayload(array $data, Person $person): array
    {
        return [
            'person_id' => $person->id,
            'employee_number' => $data['employee_number'],
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
    }
}
