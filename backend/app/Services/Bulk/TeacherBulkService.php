<?php

namespace App\Services\Bulk;

use App\Models\Teacher;
use App\Models\User;
use App\Services\AccountProvisioningService;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Массовая выдача учётных записей преподавателям.
 *
 * Репетиция первого сентября 24.08.2026 прошла путь от пустого портала до
 * открытого журнала и насчитала **около 105 шагов руками, из них 60 — один и
 * тот же сброс пароля преподавателю**. У студентов массовая выдача есть с
 * 21.08, у преподавателей её не было, и каждому пароль выдавали поодиночке.
 *
 * Устройство намеренно повторяет `StudentBulkService`, а не изобретает своё:
 * предпросмотр без записи, потом применение, и **логин с паролем возвращаются
 * списком ровно один раз** — показать человеку. Пароль после этого не хранится
 * нигде: в базе лежит только его хеш.
 *
 * **Пароли не уходят в аудит.** Отчёт перед записью в журнал теряет ветку
 * `credentials`: факт выдачи фиксируется, пароль — нет. Правило то же, что у
 * студентов, и закреплено тестом.
 */
class TeacherBulkService
{
    public const PERMISSIONS = [
        'issue_accounts' => 'teachers.bulk_accounts',
    ];

    public function __construct(
        private readonly BulkSelectionResolver $resolver,
        private readonly AccountProvisioningService $accounts,
    ) {
    }

    /** @return array<string, mixed> */
    public function preview(string $action, array $selection): array
    {
        return $this->buildReport($action, $this->resolver->teachers($selection), false);
    }

    /** @return array<string, mixed> */
    public function apply(string $action, array $selection, Request $request): array
    {
        $teachers = $this->resolver->teachers($selection);

        $report = DB::transaction(fn (): array => $this->buildReport($action, $teachers, true));

        // В аудит уходит отчёт без паролей: сам факт выдачи фиксируется, пароль — нет.
        $audited = $report;
        unset($audited['credentials']);
        AuditLogService::log('Teachers', 'bulk_'.$action, ['type' => 'Teacher', 'id' => null], null, $audited, $request, requestId: $this->requestId($request));

        return $report;
    }

    /**
     * @param  Collection<int, Teacher>  $teachers
     * @return array<string, mixed>
     */
    private function buildReport(string $action, Collection $teachers, bool $apply): array
    {
        $report = [
            'action' => $action,
            'selected' => $teachers->count(),
            'will_change' => 0,
            'changed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'items' => [],
            'sample' => [],
            'credentials' => [],
        ];

        foreach ($teachers as $teacher) {
            $result = match ($action) {
                'issue_accounts' => $this->issueAccount($teacher, $apply),
                default => ['type' => 'error', 'reason' => 'Неизвестное массовое действие.'],
            };

            $this->appendResult($report, $teacher, $result);
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private function issueAccount(Teacher $teacher, bool $apply): array
    {
        if ($teacher->user_id || ($teacher->person_id && User::query()->where('person_id', $teacher->person_id)->exists())) {
            return ['type' => 'skipped', 'reason' => 'Учётная запись уже создана.'];
        }

        if (! $apply) {
            return ['type' => 'changed', 'changes' => ['account' => 'will_be_created']];
        }

        try {
            $account = $this->accounts->provision($teacher);
        } catch (Throwable $exception) {
            return ['type' => 'error', 'reason' => $exception->getMessage()];
        }

        return [
            'type' => 'changed',
            'changes' => ['account' => 'created', 'login' => $account->login],
            'credentials' => ['login' => $account->login, 'password' => $account->password],
        ];
    }

    /** @param  array<string, mixed>  $result */
    private function appendResult(array &$report, Teacher $teacher, array $result): void
    {
        $type = $result['type'];

        if ($type === 'changed') {
            $report['will_change']++;
            $report['changed']++;
        }
        if ($type === 'skipped') {
            $report['skipped']++;
        }
        if ($type === 'error') {
            $report['errors']++;
        }

        $item = [
            'id' => $teacher->id,
            'name' => $this->name($teacher),
            'result' => $type,
            'reason' => $result['reason'] ?? null,
            'changes' => $result['changes'] ?? [],
        ];

        $report['items'][] = $item;

        if (count($report['sample']) < 10) {
            $report['sample'][] = $item;
        }

        // Пароли живут отдельной веткой отчёта, которую снимают перед аудитом.
        if (isset($result['credentials'])) {
            $report['credentials'][] = [
                'id' => $teacher->id,
                'name' => $this->name($teacher),
                'login' => $result['credentials']['login'],
                'password' => $result['credentials']['password'],
            ];
        }
    }

    private function name(Teacher $teacher): string
    {
        return trim(implode(' ', array_filter([$teacher->last_name, $teacher->first_name, $teacher->middle_name])));
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-Id') ?: Str::uuid());
    }
}
