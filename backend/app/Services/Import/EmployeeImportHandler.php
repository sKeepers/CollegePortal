<?php

namespace App\Services\Import;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Person;
use App\Models\Position;
use App\Services\AccountProvisioningService;
use App\Services\PersonService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class EmployeeImportHandler extends AbstractImportHandler
{
    public function __construct(private readonly PersonService $personService, private readonly AccountProvisioningService $accounts)
    {
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
            'department' => ['label' => 'Подразделение', 'required' => false, 'aliases' => ['подразделение', 'отделение', 'отдел', 'department']],
            'position' => ['label' => 'Должность', 'required' => false, 'aliases' => ['должность', 'position']],
            'employment_type' => ['label' => 'Тип занятости', 'required' => false, 'aliases' => ['тип занятости', 'employment_type']],
            'work_schedule_code' => ['label' => 'Рабочий график', 'required' => false, 'aliases' => ['рабочий график', 'график работы', 'work_schedule_code']],
            'workload_rate' => ['label' => 'Ставка', 'required' => false, 'aliases' => ['ставка', 'rate', 'workload_rate']],
            'status' => ['label' => 'Статус', 'required' => false, 'aliases' => ['статус', 'активен', 'status']],
            'hired_at' => ['label' => 'Дата приема', 'required' => false, 'aliases' => ['дата приема', 'hired_at', 'принят']],
            'dismissed_at' => ['label' => 'Дата увольнения', 'required' => false, 'aliases' => ['дата увольнения', 'dismissed_at', 'уволен']],
            'is_teacher' => ['label' => 'Является преподавателем', 'required' => false, 'aliases' => ['преподаватель', 'is_teacher']],
            'auto_account' => ['label' => 'Создать учетную запись', 'required' => false, 'aliases' => ['создать учетную запись', 'auto_account']],
        ];
    }

    public function templateHeaders(): array
    {
        return ['Табельный номер','Фамилия','Имя','Отчество','Дата рождения','Телефон','Email','СНИЛС','Подразделение','Должность','Тип занятости','Рабочий график','Ставка','Статус','Дата приема','Дата увольнения','Является преподавателем','Создать учетную запись'];
    }

    public function templateExample(): array
    {
        return ['EMP-0001','Иванова','Мария','Петровна','15.03.1985','+79990000001','ivanova@example.test','123-456-789 00','Учебная часть','Методист','full_time','weekday_0900_1800','1','active','01.09.2026','','нет','нет'];
    }

    public function prepare(array $data): array
    {
        $data['employee_number'] = $data['employee_number'] ?? null;
        $data['birth_date'] = $this->normalizeDate($data['birth_date'] ?? null);
        $data['hired_at'] = $this->normalizeDate($data['hired_at'] ?? null);
        $data['dismissed_at'] = $this->normalizeDate($data['dismissed_at'] ?? null);
        $data['status'] = $this->normalizeStatus($data['status'] ?? 'active');
        $data['employment_type'] = $this->normalizeEmploymentType($data['employment_type'] ?? 'full_time');
        $data['workload_rate'] = !isset($data['workload_rate']) || $data['workload_rate'] === null || $data['workload_rate'] === '' ? 1 : (float) str_replace(',', '.', (string) $data['workload_rate']);
        $data['is_teacher'] = $this->booleanValue($data['is_teacher'] ?? false);
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
            'work_schedule_code' => ['nullable','in:weekday_0900_1800,weekday_0900_1700,shift_2_2_0800_2000,flexible'],
            'workload_rate' => ['nullable','numeric','min:0','max:2'],
            'status' => ['nullable','in:candidate,active,probation,vacation,sick_leave,maternity_leave,business_trip,suspended,dismissed'],
            'hired_at' => ['nullable','date'],
            'dismissed_at' => ['nullable','date'],
            'is_teacher' => ['boolean'],
            'auto_account' => ['boolean'],
        ];
    }

    public function findExisting(array $data): ?Model
    {
        if (!empty($data['employee_number']) && ($employee = Employee::where('employee_number', $data['employee_number'])->first())) {
            return $employee;
        }

        $employees = $this->matchingEmployees($data);
        if ($employees->count() > 1) {
            throw new RuntimeException('Найдено несколько сотрудников с совпадающим ФИО. Уточните табельный номер, СНИЛС, email или телефон.');
        }

        return $employees->first();
    }

    public function businessValidationErrors(array $data): array
    {
        $errors = [];
        if (!empty($data['department']) && !$this->departmentId($data['department'])) { $errors['department'] = ['Подразделение не найдено.']; }
        return $errors;
    }

    public function import(array $data, string $mode): string
    {
        $existing = $this->findExisting($data);
        if ($mode === self::MODE_UPDATE && !$existing) { return 'skipped'; }
        if ($existing && $mode === self::MODE_SKIP_DUPLICATES) { return 'skipped'; }
        if ($existing && $mode === self::MODE_CREATE) { throw new RuntimeException('Дубликат по табельному номеру.'); }

        $person = $existing?->person ?: $this->resolvePerson($data);
        $payload = $this->employeePayload($data, $person->id);

        if ($existing) {
            $existing->update($payload);
            return 'updated';
        }

        $employee = Employee::create($payload);
        if ($data['auto_account']) {
            $this->accounts->provision($employee);
        }
        return 'created';
    }

    protected function virtualFields(): array
    {
        return ['auto_account'];
    }

    private function resolvePerson(array $data): Person
    {
        $people = $this->matchingPeople($data);
        if ($people->count() > 1) { throw new RuntimeException('Найдено несколько Person с совпадающим ФИО. Уточните СНИЛС, email или телефон.'); }
        if ($people->count() === 1) { return $people->first(); }

        $personData = [
            'last_name' => $data['last_name'],
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'snils' => $data['snils'] ?? null,
            'status' => $data['status'] === 'dismissed' ? 'inactive' : 'active',
        ];
        $duplicates = $this->personService->findPossibleDuplicates($personData);
        if ($duplicates->count() > 1) { throw new RuntimeException('Найдено несколько возможных Person. Уточните СНИЛС, email или телефон.'); }
        if ($duplicates->count() === 1) { return $duplicates->first(); }
        return $this->personService->createPerson($personData);
    }

    private function employeePayload(array $data, int $personId): array
    {
        return [
            'person_id' => $personId,
            'employee_number' => ($data['employee_number'] ?? null) ?: $this->newEmployeeNumber(),
            'status' => $data['status'] ?: 'active',
            'employment_type' => $data['employment_type'] ?: 'full_time',
            'work_schedule_code' => $data['work_schedule_code'] ?? null,
            'hired_at' => $data['hired_at'] ?: null,
            'dismissed_at' => $data['dismissed_at'] ?? null,
            'primary_department_id' => $this->departmentId($data['department'] ?? null),
            'primary_position_id' => $this->positionId($data['position'] ?? null),
            'workload_rate' => $data['workload_rate'] ?? 1,
            'is_teacher' => (bool) ($data['is_teacher'] ?? false),
        ];
    }

    private function departmentId(?string $name): ?int { return $name ? Department::where('name', $name)->orWhere('code', $name)->value('id') : null; }
    private function positionId(?string $name): ?int
    {
        if (!$name) { return null; }
        $position = Position::whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->orWhere('code', $name)
            ->first();
        if ($position) { return $position->id; }

        $baseCode = Str::slug($name) ?: 'position';
        $code = $baseCode;
        $suffix = 2;
        while (Position::where('code', $code)->exists()) { $code = "{$baseCode}-{$suffix}"; $suffix++; }
        return Position::create(['code' => $code, 'name' => $name])->id;
    }

    /** @return Collection<int, Employee> */
    private function matchingEmployees(array $data): Collection
    {
        return Employee::query()->whereHas('person', fn ($query) => $this->applyFullName($query, $data))->get();
    }

    /** @return Collection<int, Person> */
    private function matchingPeople(array $data): Collection
    {
        return Person::query()->where(fn ($query) => $this->applyFullName($query, $data))->get();
    }

    private function applyFullName($query, array $data): void
    {
        $query->where('last_name', $data['last_name'])
            ->where('first_name', $data['first_name'])
            ->when($data['middle_name'] ?? null, fn ($query, $middleName) => $query->where('middle_name', $middleName), fn ($query) => $query->whereNull('middle_name'));
    }

    private function newEmployeeNumber(): string
    {
        do {
            $number = 'EMP-IMPORT-'.Str::upper(Str::random(12));
        } while (Employee::where('employee_number', $number)->exists());

        return $number;
    }

    private function normalizeStatus(?string $value): string
    {
        $map = ['1'=>'active','0'=>'dismissed','кандидат'=>'candidate','активен'=>'active','испытательный срок'=>'probation','отпуск'=>'vacation','больничный'=>'sick_leave','декрет'=>'maternity_leave','командировка'=>'business_trip','отстранен'=>'suspended','уволен'=>'dismissed'];
        $key = mb_strtolower(trim((string) $value));
        return $map[$key] ?? ($key ?: 'active');
    }

    private function normalizeEmploymentType(?string $value): string
    {
        $map = ['полная занятость'=>'full_time','полная'=>'full_time','частичная'=>'part_time','внутреннее совместительство'=>'internal_part_time','внешнее совместительство'=>'external_part_time','договор'=>'contract'];
        $key = mb_strtolower(trim((string) $value));
        return $map[$key] ?? ($key ?: 'full_time');
    }
}
