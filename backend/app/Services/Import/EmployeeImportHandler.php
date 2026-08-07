<?php

namespace App\Services\Import;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Person;
use App\Models\Position;
use App\Services\AccountProvisioningService;
use App\Services\HrService;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EmployeeImportHandler extends AbstractImportHandler
{
    public function __construct(
        private readonly HrService $hrService,
        private readonly AccountProvisioningService $accounts,
    ) {
    }

    public function type(): string { return 'employees'; }
    public function label(): string { return 'Сотрудники'; }
    public function modelClass(): string { return Employee::class; }
    public function keyFields(): array { return ['employee_number']; }

    public function fields(): array
    {
        return [
            'employee_number' => ['label' => 'Табельный номер', 'required' => false, 'aliases' => ['табельный номер', 'employee_number', 'номер сотрудника']],
            'last_name' => ['label' => 'Фамилия', 'required' => true, 'aliases' => ['фамилия', 'last_name']],
            'first_name' => ['label' => 'Имя', 'required' => true, 'aliases' => ['имя', 'first_name']],
            'middle_name' => ['label' => 'Отчество', 'required' => false, 'aliases' => ['отчество', 'middle_name']],
            'birth_date' => ['label' => 'Дата рождения', 'required' => false, 'aliases' => ['дата рождения', 'birth_date']],
            'phone' => ['label' => 'Телефон', 'required' => false, 'aliases' => ['телефон', 'phone']],
            'email' => ['label' => 'Email', 'required' => false, 'aliases' => ['email', 'почта', 'e-mail']],
            'snils' => ['label' => 'СНИЛС', 'required' => false, 'aliases' => ['снилс', 'snils']],
            'department' => ['label' => 'Подразделение', 'required' => false, 'aliases' => ['подразделение', 'отдел', 'department']],
            'position' => ['label' => 'Должность', 'required' => false, 'aliases' => ['должность', 'position']],
            'employment_type' => ['label' => 'Занятость', 'required' => false, 'aliases' => ['занятость', 'тип занятости', 'employment_type']],
            'workload_rate' => ['label' => 'Ставка', 'required' => false, 'aliases' => ['ставка', 'rate', 'workload_rate']],
            'status' => ['label' => 'Статус', 'required' => false, 'aliases' => ['статус', 'status']],
            'hired_at' => ['label' => 'Дата приема', 'required' => true, 'aliases' => ['дата приема', 'hired_at', 'принят']],
            'dismissed_at' => ['label' => 'Дата увольнения', 'required' => false, 'aliases' => ['дата увольнения', 'dismissed_at', 'уволен']],
            'is_teacher' => ['label' => 'Преподаватель', 'required' => false, 'aliases' => ['преподаватель', 'является преподавателем', 'is_teacher']],
            'work_schedule_code' => ['label' => 'Рабочий график', 'required' => false, 'aliases' => ['рабочий график', 'график работы', 'график', 'work_schedule_code']],
            'auto_account' => ['label' => 'Создать учетную запись', 'required' => false, 'aliases' => ['создать учетную запись', 'auto_account']],
        ];
    }

    public function templateHeaders(): array
    {
        return ['Табельный номер', 'Фамилия', 'Имя', 'Отчество', 'Email', 'Телефон', 'Подразделение', 'Должность', 'Дата приема', 'Статус', 'Занятость', 'Ставка', 'Преподаватель', 'Рабочий график', 'Создать учетную запись'];
    }

    public function templateExample(): array
    {
        return ['', 'Примерова', 'Александра', 'Сергеевна', 'employee@example.test', '+70000000000', 'Учебная часть', 'Методист', '01.09.2026', 'Активен', 'Полная занятость', '1', 'Да', 'Пн–Пт, 09:00–18:00', 'Нет'];
    }

    public function prepare(array $data): array
    {
        $data['employee_number'] = filled($data['employee_number'] ?? null) ? trim($data['employee_number']) : null;
        $data['birth_date'] = $this->normalizeDate($data['birth_date'] ?? null);
        $data['hired_at'] = $this->normalizeDate($data['hired_at'] ?? null);
        $data['dismissed_at'] = $this->normalizeDate($data['dismissed_at'] ?? null);
        $data['email'] = filled($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null;
        $data['phone'] = filled($data['phone'] ?? null) ? preg_replace('/\D+/', '', $data['phone']) : null;
        $data['snils'] = filled($data['snils'] ?? null) ? preg_replace('/\D+/', '', $data['snils']) : null;
        $data['status'] = $this->normalizeStatus($data['status'] ?? 'active');
        $data['employment_type'] = $this->normalizeEmploymentType($data['employment_type'] ?? 'full_time');
        $data['workload_rate'] = !isset($data['workload_rate']) || $data['workload_rate'] === null || $data['workload_rate'] === '' ? 1 : (float) str_replace(',', '.', (string) $data['workload_rate']);
        $data['is_teacher'] = $this->booleanValue($data['is_teacher'] ?? false);
        $data['work_schedule_code'] = $this->normalizeWorkSchedule($data['work_schedule_code'] ?? null);
        $data['auto_account'] = $this->booleanValue($data['auto_account'] ?? false);
        return $data;
    }

    public function rules(): array
    {
        return [
            'employee_number' => ['nullable','string','max:100'],
            'last_name' => ['required','string','max:255'],
            'first_name' => ['required','string','max:255'],
            'middle_name' => ['nullable','string','max:255'],
            'birth_date' => ['nullable','date'],
            'phone' => ['nullable','string','max:50'],
            'email' => ['nullable','email','max:255'],
            'snils' => ['nullable','string','max:50'],
            'department' => ['nullable','string','max:255'],
            'position' => ['nullable','string','max:255'],
            'employment_type' => ['nullable','in:full_time,part_time,internal_part_time,external_part_time,contract'],
            'workload_rate' => ['nullable','numeric','min:0','max:2'],
            'status' => ['nullable','in:candidate,active,probation,vacation,sick_leave,maternity_leave,business_trip,suspended,dismissed'],
            'hired_at' => ['nullable','date'],
            'dismissed_at' => ['nullable','date'],
            'is_teacher' => ['boolean'],
            'work_schedule_code' => ['nullable', 'in:'.implode(',', Employee::WORK_SCHEDULE_CODES)],
            'auto_account' => ['boolean'],
        ];
    }

    public function findExisting(array $data): ?Model
    {
        if (!empty($data['employee_number']) && ($employee = Employee::where('employee_number', $data['employee_number'])->first())) {
            return $employee;
        }

        $personIds = collect();
        foreach (['snils', 'email', 'phone'] as $field) {
            if (!empty($data[$field])) {
                $personIds = $personIds->merge(Person::where($field, $data[$field])->pluck('id'));
            }
        }
        if ($personIds->isEmpty() && !empty($data['birth_date'])) {
            $personIds = Person::where('last_name', $data['last_name'])
                ->where('first_name', $data['first_name'])
                ->where('middle_name', $data['middle_name'] ?? null)
                ->where('birth_date', $data['birth_date'])
                ->pluck('id');
        }
        if ($personIds->isEmpty()) {
            $personIds = Person::where('last_name', $data['last_name'])
                ->where('first_name', $data['first_name'])
                ->where('middle_name', $data['middle_name'] ?? null)
                ->pluck('id');
        }

        $employees = Employee::whereIn('person_id', $personIds->unique())->get();
        if ($employees->count() > 1) {
            throw new RuntimeException('Найдено несколько возможных сотрудников. Уточните табельный номер, СНИЛС, email или телефон.');
        }

        return $employees->first();
    }

    public function businessValidationErrors(array $data): array
    {
        $errors = [];
        if ($error = $this->referenceError(Department::class, $data['department'] ?? null, 'Подразделение')) { $errors['department'] = [$error]; }
        if ($error = $this->referenceError(Position::class, $data['position'] ?? null, 'Должность')) { $errors['position'] = [$error]; }
        return $errors;
    }

    public function import(array $data, string $mode): string
    {
        $existing = $this->findExisting($data);
        if ($mode === self::MODE_UPDATE && !$existing) { return 'skipped'; }
        if ($existing && $mode === self::MODE_SKIP_DUPLICATES) { return 'skipped'; }
        if ($existing && $mode === self::MODE_CREATE) { throw new RuntimeException('Дубликат по табельному номеру.'); }

        if ($existing) {
            $this->hrService->updateEmployee($existing, $this->employeeData($data));
            return 'updated';
        }

        $employee = $this->hrService->createEmployee($this->employeeData($data));
        if ($data['auto_account'] ?? false) {
            $this->accounts->provision($employee);
        }

        return 'created';
    }

    private function employeeData(array $data): array
    {
        return [
            'employee_number' => $data['employee_number'] ?? null,
            'last_name' => $data['last_name'],
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'snils' => $data['snils'] ?? null,
            'status' => $data['status'] ?: 'active',
            'employment_type' => $data['employment_type'] ?: 'full_time',
            'hired_at' => $data['hired_at'] ?? null,
            'dismissed_at' => $data['dismissed_at'] ?? null,
            'primary_department_id' => $this->referenceId(Department::class, $data['department'] ?? null),
            'primary_position_id' => $this->referenceId(Position::class, $data['position'] ?? null),
            'workload_rate' => $data['workload_rate'] ?: 1,
            'is_teacher' => (bool) ($data['is_teacher'] ?? false),
            'work_schedule_code' => $data['work_schedule_code'] ?? null,
        ];
    }

    private function referenceId(string $model, ?string $value): ?int
    {
        return $value ? $model::query()->where('name', $value)->orWhere('code', $value)->value('id') : null;
    }

    private function referenceError(string $model, ?string $value, string $label): ?string
    {
        if (!$value) { return null; }
        $count = $model::query()->where('name', $value)->orWhere('code', $value)->count();
        return $count === 0 ? "{$label} не найдено." : ($count > 1 ? "{$label} сопоставляется неоднозначно: используйте уникальный код." : null);
    }

    private function normalizeStatus(?string $value): string
    {
        $map = ['кандидат'=>'candidate','активен'=>'active','испытательный срок'=>'probation','отпуск'=>'vacation','больничный'=>'sick_leave','декрет'=>'maternity_leave','командировка'=>'business_trip','отстранен'=>'suspended','уволен'=>'dismissed'];
        $key = mb_strtolower(trim((string) $value));
        return $map[$key] ?? ($key ?: 'active');
    }

    private function normalizeWorkSchedule(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') { return null; }
        if (in_array($value, Employee::WORK_SCHEDULE_CODES, true)) { return $value; }

        // Из таблицы приходит человекочитаемая подпись, причём тире и пробелы у всех разные.
        $key = mb_strtolower($value);
        $key = str_replace(['–', '—'], '-', $key);
        $key = (string) preg_replace('/\s*-\s*/', '-', $key);

        return match (true) {
            str_contains($key, '9:00-18:00') => 'weekday_0900_1800',
            str_contains($key, '9:00-17:00') => 'weekday_0900_1700',
            str_contains($key, '2/2') => 'shift_2_2_0800_2000',
            str_contains($key, 'гибк') => 'flexible',
            // Неизвестное значение возвращаем как есть: правило in: покажет строку и колонку.
            default => $value,
        };
    }

    private function normalizeEmploymentType(?string $value): string
    {
        $map = ['полная занятость'=>'full_time','полная'=>'full_time','частичная'=>'part_time','внутреннее совместительство'=>'internal_part_time','внешнее совместительство'=>'external_part_time','договор'=>'contract'];
        $key = mb_strtolower(trim((string) $value));
        return $map[$key] ?? ($key ?: 'full_time');
    }
}
