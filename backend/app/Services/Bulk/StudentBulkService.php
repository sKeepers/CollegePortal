<?php

namespace App\Services\Bulk;

use App\Models\DigitalIdentity;
use App\Models\Student;
use App\Models\User;
use App\Services\AccountProvisioningService;
use App\Services\AuditLogService;
use Throwable;
use App\Support\Csv\CsvExport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentBulkService
{
    public const PERMISSIONS = [
        'assign_group' => 'students.bulk_group',
        'change_status' => 'students.bulk_status',
        'change_course' => 'students.bulk_course',
        'change_education_form' => 'students.bulk_education',
        'change_funding_form' => 'students.bulk_education',
        'issue_digital_passes' => 'students.bulk_passes',
        'issue_accounts' => 'students.bulk_accounts',
        'archive_selected' => 'students.bulk_archive',
        'export_selected' => 'students.bulk_export',
    ];

    public function __construct(
        private readonly BulkSelectionResolver $resolver,
        private readonly AccountProvisioningService $accounts,
    ) {
    }

    public function preview(string $action, array $selection, array $payload = []): array
    {
        $students = $this->resolver->students($selection);
        return $this->buildReport($action, $students, $payload, false);
    }

    public function apply(string $action, array $selection, array $payload, Request $request): array|StreamedResponse
    {
        $students = $this->resolver->students($selection);

        if ($action === 'export_selected') {
            AuditLogService::log('Students', 'bulk_export_selected', ['type' => 'Student', 'id' => null], null, [
                'selected' => $students->count(),
            ], $request, requestId: $this->requestId($request));

            return $this->export($students);
        }

        $report = DB::transaction(function () use ($action, $students, $payload): array {
            return $this->buildReport($action, $students, $payload, true);
        });

        // В аудит уходит отчет без паролей: сам факт выдачи фиксируется, пароль — нет.
        $audited = $report;
        unset($audited['credentials']);
        AuditLogService::log('Students', 'bulk_'.$action, ['type' => 'Student', 'id' => null], null, $audited, $request, requestId: $this->requestId($request));

        return $report;
    }

    private function buildReport(string $action, Collection $students, array $payload, bool $apply): array
    {
        $report = $this->baseReport($action, $students->count());

        foreach ($students as $student) {
            $result = match ($action) {
                'assign_group' => $this->assignGroup($student, $payload, $apply),
                'change_status' => $this->changeStatus($student, $payload, $apply),
                'change_course' => $this->changeCourse($student, $payload, $apply),
                'change_education_form' => $this->changeEducationForm($student, $payload, $apply),
                'change_funding_form' => $this->changeFundingForm($student, $payload, $apply),
                'issue_digital_passes' => $this->issueDigitalPass($student, $payload, $apply),
                'issue_accounts' => $this->issueAccount($student, $apply),
                'archive_selected' => $this->archive($student, $apply),
                default => ['type' => 'error', 'reason' => 'Неизвестное массовое действие.'],
            };

            $this->appendResult($report, $student, $result);
        }

        return $report;
    }

    private function assignGroup(Student $student, array $payload, bool $apply): array
    {
        $groupId = (int) ($payload['group_id'] ?? 0);
        if ($groupId <= 0) {
            return ['type' => 'error', 'reason' => 'Не указана группа.'];
        }
        if ((int) $student->group_id === $groupId) {
            return ['type' => 'skipped', 'reason' => 'Студент уже состоит в этой группе.', 'changes' => ['group_id' => $groupId]];
        }
        if ($apply) {
            $student->update(['group_id' => $groupId]);
        }
        return ['type' => 'changed', 'changes' => ['group_id' => $groupId]];
    }

    private function changeStatus(Student $student, array $payload, bool $apply): array
    {
        $status = trim((string) ($payload['status'] ?? ''));
        if ($status === '') {
            return ['type' => 'error', 'reason' => 'Не указан новый статус.'];
        }
        if ($student->status === $status) {
            return ['type' => 'skipped', 'reason' => 'Статус уже установлен.', 'changes' => ['status' => $status]];
        }
        if ($apply) {
            $student->update(['status' => $status]);
        }
        return ['type' => 'changed', 'changes' => ['status' => $status]];
    }

    private function changeCourse(Student $student, array $payload, bool $apply): array
    {
        $course = (int) ($payload['course'] ?? 0);
        if ($course < 1 || $course > 6) {
            return ['type' => 'error', 'reason' => 'Курс должен быть от 1 до 6.'];
        }
        if ((int) $student->course === $course) {
            return ['type' => 'skipped', 'reason' => 'Курс уже установлен.', 'changes' => ['course' => $course]];
        }
        if ($apply) {
            $student->update(['course' => $course]);
        }
        return ['type' => 'changed', 'changes' => ['course' => $course]];
    }

    private function changeEducationForm(Student $student, array $payload, bool $apply): array
    {
        $form = trim((string) ($payload['education_form'] ?? ''));
        if ($form === '') {
            return ['type' => 'error', 'reason' => 'Не указана форма обучения.'];
        }
        if ($student->education_form === $form) {
            return ['type' => 'skipped', 'reason' => 'Форма обучения уже установлена.', 'changes' => ['education_form' => $form]];
        }
        if ($apply) {
            $student->update(['education_form' => $form]);
        }
        return ['type' => 'changed', 'changes' => ['education_form' => $form]];
    }

    private function changeFundingForm(Student $student, array $payload, bool $apply): array
    {
        $form = trim((string) ($payload['funding_form'] ?? ''));
        if ($form === '') {
            return ['type' => 'error', 'reason' => 'Не указана форма финансирования.'];
        }
        if ($student->funding_form === $form) {
            return ['type' => 'skipped', 'reason' => 'Форма финансирования уже установлена.', 'changes' => ['funding_form' => $form]];
        }
        if ($apply) {
            $student->update(['funding_form' => $form]);
        }
        return ['type' => 'changed', 'changes' => ['funding_form' => $form]];
    }

    private function issueDigitalPass(Student $student, array $payload, bool $apply): array
    {
        if (! $student->person_id) {
            return ['type' => 'skipped', 'reason' => 'У студента нет связанной Person.'];
        }

        $activeExists = DigitalIdentity::query()
            ->where('entity_type', DigitalIdentity::ENTITY_STUDENT)
            ->where('entity_id', $student->id)
            ->where('status', DigitalIdentity::STATUS_ACTIVE)
            ->exists();

        if ($activeExists) {
            return ['type' => 'skipped', 'reason' => 'Активный QR-пропуск уже существует.'];
        }

        $changes = ['digital_pass' => 'active'];
        if ($apply) {
            $identity = DigitalIdentity::create([
                'person_id' => $student->person_id,
                'entity_type' => DigitalIdentity::ENTITY_STUDENT,
                'entity_id' => $student->id,
                'token' => (string) Str::uuid(),
                'status' => DigitalIdentity::STATUS_ACTIVE,
                'issued_at' => now(),
                'expires_at' => $payload['expires_at'] ?? null,
            ]);
            $changes['digital_identity_id'] = $identity->id;
        }
        return ['type' => 'changed', 'changes' => $changes];
    }

    /**
     * Учетная запись на группу разом. Пароль возвращается ровно один раз — в
     * ответе на apply — и больше нигде не появляется: в базе он хеширован, в
     * журнал аудита не попадает, повторно узнать его нельзя, только сбросить.
     * Поэтому оператор обязан распечатать или выгрузить карточки сразу.
     */
    private function issueAccount(Student $student, bool $apply): array
    {
        if ($student->user_id || ($student->person_id && User::query()->where('person_id', $student->person_id)->exists())) {
            return ['type' => 'skipped', 'reason' => 'Учетная запись уже создана.'];
        }

        if (! $apply) {
            return ['type' => 'changed', 'changes' => ['account' => 'will_be_created']];
        }

        try {
            $account = $this->accounts->provision($student);
        } catch (Throwable $exception) {
            return ['type' => 'error', 'reason' => $exception->getMessage()];
        }

        $result = [
            'type' => 'changed',
            'changes' => ['account' => 'created', 'login' => $account->login],
            'credentials' => [
                'login' => $account->login,
                'password' => $account->password,
            ],
        ];

        // Учётная запись создана, но телефон в карточке негоден — логин пришлось
        // строить от фамилии. Сказать об этом надо здесь: иначе испорченный
        // телефон останется незамеченным до первой попытки позвонить.
        $phone = preg_replace('/\D+/', '', (string) ($student->phone ?: $student->person?->phone)) ?? '';

        if ($phone !== '' && ! preg_match('/^(?:7|8)?\d{10}$/', $phone)) {
            $result['reason'] = sprintf(
                'Телефон в карточке не похож на номер (%d цифр), логин построен от фамилии.',
                mb_strlen($phone),
            );
        }

        return $result;
    }

    private function archive(Student $student, bool $apply): array
    {
        if ($student->archived_at) {
            return ['type' => 'skipped', 'reason' => 'Студент уже архивирован.'];
        }
        if ($apply) {
            $student->update(['status' => 'archived', 'archived_at' => now()]);
        }
        return ['type' => 'changed', 'changes' => ['status' => 'archived', 'archived_at' => now()->toISOString()]];
    }

    private function export(Collection $students): StreamedResponse
    {
        return CsvExport::download('students-selected.csv', ['id', 'fio', 'group', 'status', 'course', 'education_form', 'funding_form', 'phone_masked', 'email_masked'], function (callable $row) use ($students): void {
            foreach ($students as $student) {
                $row([
                    $student->id,
                    $this->name($student),
                    $student->group?->name,
                    $student->status,
                    $student->course,
                    $student->education_form,
                    $student->funding_form,
                    $this->mask($student->phone),
                    $this->maskEmail($student->email),
                ]);
            }
        });
    }

    private function baseReport(string $action, int $total): array
    {
        return ['action' => $action, 'selected' => $total, 'will_change' => 0, 'changed' => 0, 'skipped' => 0, 'errors' => 0, 'items' => [], 'sample' => [], 'credentials' => []];
    }

    private function appendResult(array &$report, Student $student, array $result): void
    {
        $type = $result['type'];
        if ($type === 'changed') { $report['will_change']++; $report['changed']++; }
        if ($type === 'skipped') { $report['skipped']++; }
        if ($type === 'error') { $report['errors']++; }
        $item = ['id' => $student->id, 'name' => $this->name($student), 'result' => $type, 'reason' => $result['reason'] ?? null, 'changes' => $result['changes'] ?? []];
        $report['items'][] = $item;
        if (count($report['sample']) < 10) { $report['sample'][] = $item; }

        // Пароли живут отдельной веткой отчета, которую снимают перед аудитом.
        if (isset($result['credentials'])) {
            $report['credentials'][] = [
                'id' => $student->id,
                'name' => $this->name($student),
                'group' => $student->group?->name,
                'login' => $result['credentials']['login'],
                'password' => $result['credentials']['password'],
            ];
        }
    }

    private function name(Student $student): string
    {
        return trim(implode(' ', array_filter([$student->last_name, $student->first_name, $student->middle_name]))) ?: "Студент #{$student->id}";
    }

    private function mask(?string $value): ?string
    {
        if (! $value) { return null; }
        $length = mb_strlen($value);
        return $length <= 4 ? str_repeat('*', $length) : mb_substr($value, 0, 2).str_repeat('*', max(0, $length - 4)).mb_substr($value, -2);
    }

    private function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) { return $this->mask($email); }
        [$name, $domain] = explode('@', $email, 2);
        return mb_substr($name, 0, 1).'***@'.$domain;
    }

    private function requestId(Request $request): string
    {
        return $request->header('X-Idempotency-Key') ?: $request->header('X-Request-ID') ?: (string) Str::uuid();
    }
}
