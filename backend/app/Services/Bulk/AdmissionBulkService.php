<?php

namespace App\Services\Bulk;

use App\Models\ApplicantApplication;
use App\Models\ApplicantApplicationDocument;
use App\Models\Person;
use App\Models\Student;
use App\Services\ApplicantApplicationDocumentService;
use App\Services\ApplicantApplicationEventService;
use App\Services\ApplicantDocumentRegistryService;
use App\Services\AuditLogService;
use App\Services\PersonService;
use App\Support\Csv\CsvExport;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdmissionBulkService
{
    public const PERMISSIONS = [
        'change_status' => 'admissions.bulk_status',
        'mark_documents_provided' => 'admissions.bulk_documents',
        'mark_document_type_received' => 'admissions.documents.receive',
        'send_document_type_review' => 'admissions.documents.receive',
        'verify_document_type' => 'admissions.documents.verify',
        'reject_document_type' => 'admissions.documents.reject',
        'mark_recommended' => 'admissions.bulk_recommend',
        'assign_program' => 'admissions.bulk_assign',
        'export_selected' => 'admissions.bulk_export',
        'enroll_selected' => 'admissions.bulk_enroll',
    ];

    public function __construct(
        private readonly BulkSelectionResolver $resolver,
        private readonly ApplicantApplicationDocumentService $documentService,
        private readonly ApplicantDocumentRegistryService $documentRegistry,
        private readonly ApplicantApplicationEventService $eventService,
        private readonly PersonService $personService,
    ) {
    }

    public function preview(string $action, array $selection, array $payload = []): array
    {
        $applications = $this->resolver->admissions($selection);
        return $this->buildReport($action, $applications, $payload, false, $selection);
    }

    public function apply(string $action, array $selection, array $payload, Request $request): array|StreamedResponse
    {
        $applications = $this->resolver->admissions($selection);

        if ($action === 'export_selected') {
            AuditLogService::log('Admissions', 'bulk_export_selected', ['type' => 'ApplicantApplication', 'id' => null], null, [
                'selected' => $applications->count(),
            ], $request, requestId: $this->requestId($request));

            return $this->export($applications);
        }

        $report = DB::transaction(function () use ($action, $applications, $payload, $selection, $request): array {
            return $this->buildReport($action, $applications, $payload, true, $selection, $request->user());
        });

        AuditLogService::log('Admissions', 'bulk_'.$action, ['type' => 'ApplicantApplication', 'id' => null], null, $report, $request, requestId: $this->requestId($request));

        return $report;
    }

    private function buildReport(string $action, Collection $applications, array $payload, bool $apply, array $selection = [], ?User $user = null): array
    {
        $report = $this->baseReport($action, $applications->count(), $selection);

        foreach ($applications as $application) {
            $result = match ($action) {
                'change_status' => $this->changeStatus($application, $payload, $apply),
                'mark_documents_provided' => $this->markDocumentsProvided($application, $apply),
                'mark_document_type_received' => $this->changeDocumentTypeStatus($application, $payload, ApplicantApplicationDocument::STATUS_RECEIVED, $apply, $user),
                'send_document_type_review' => $this->changeDocumentTypeStatus($application, $payload, ApplicantApplicationDocument::STATUS_UNDER_REVIEW, $apply, $user),
                'verify_document_type' => $this->changeDocumentTypeStatus($application, $payload, ApplicantApplicationDocument::STATUS_VERIFIED, $apply, $user),
                'reject_document_type' => $this->changeDocumentTypeStatus($application, $payload, ApplicantApplicationDocument::STATUS_REJECTED, $apply, $user),
                'mark_recommended' => $this->markRecommended($application, $apply),
                'assign_program' => $this->assignProgram($application, $payload, $apply),
                'enroll_selected' => $this->enroll($application, $payload, $apply),
                default => ['type' => 'error', 'reason' => 'Неизвестное массовое действие.'],
            };

            $this->appendResult($report, $application, $result);
        }

        return $report;
    }

    private function changeStatus(ApplicantApplication $application, array $payload, bool $apply): array
    {
        $status = trim((string) ($payload['status'] ?? ''));
        if ($status === '') {
            return ['type' => 'error', 'reason' => 'Не указан новый статус.'];
        }
        if ($application->status === $status) {
            return ['type' => 'skipped', 'reason' => 'Статус уже установлен.', 'changes' => ['status' => $status]];
        }
        if ($apply) {
            $old = $application->status;
            $application->update(['status' => $status]);
            $this->eventService->record($application, 'bulk_status_changed', 'Массовое изменение статуса', "Статус изменен с {$old} на {$status}.", ['from' => $old, 'to' => $status]);
        }
        return ['type' => 'changed', 'changes' => ['status' => $status]];
    }

    private function markDocumentsProvided(ApplicantApplication $application, bool $apply): array
    {
        if ($application->documents_provided) {
            return ['type' => 'already_set', 'reason' => 'Получение документов уже подтверждено.', 'changes' => ['documents_provided' => true]];
        }

        if ($apply) {
            $application->update(['documents_provided' => true]);
            $this->eventService->record($application, 'bulk_documents_provided', 'Получение документов подтверждено', 'Административный признак получения документов установлен массовой операцией. Записи отдельных документов не изменялись.');
        }
        return ['type' => 'changed', 'changes' => ['documents_provided' => true]];
    }

    private function changeDocumentTypeStatus(ApplicantApplication $application, array $payload, string $status, bool $apply, ?User $user): array
    {
        $typeCode = trim((string) ($payload['document_type'] ?? $payload['type'] ?? ''));
        $typeCode = $typeCode === 'consent' ? 'personal_data_consent' : $typeCode;
        if ($typeCode === '') {
            return ['type' => 'error', 'reason' => 'Не указан тип документа.'];
        }

        $type = $this->documentRegistry->documentTypes()->firstWhere('code', $typeCode);
        if (! $type) {
            return ['type' => 'error', 'reason' => 'Неизвестный тип документа.'];
        }

        if ($status === ApplicantApplicationDocument::STATUS_REJECTED && trim((string) ($payload['reason'] ?? '')) === '') {
            return ['type' => 'error', 'reason' => 'Для отклонения документа нужна причина.'];
        }

        $document = $application->documents()
            ->where(function ($query) use ($typeCode, $type) {
                $query->where('document_type_id', $type->id)
                    ->orWhere('type', $typeCode);
            })
            ->first();

        if ($document?->status === $status) {
            return ['type' => 'already_set', 'reason' => 'Документ уже имеет выбранный статус.', 'changes' => ['document_type' => $typeCode, 'status' => $status]];
        }

        if ($apply) {
            if (! $user) {
                return ['type' => 'error', 'reason' => 'Не определен пользователь операции.'];
            }

            $this->documentService->ensureDefaultDocuments($application);
            $document = $this->documentRegistry->documentByType($application, $typeCode);

            match ($status) {
                ApplicantApplicationDocument::STATUS_VERIFIED => $this->documentRegistry->verify($application, $document, $user, ['comment' => $payload['comment'] ?? null]),
                ApplicantApplicationDocument::STATUS_REJECTED => $this->documentRegistry->reject($application, $document, $user, (string) $payload['reason'], $payload['comment'] ?? null),
                default => $this->documentRegistry->receive($application, $typeCode, $user, [
                    'status' => $status,
                    'comment' => $payload['comment'] ?? null,
                    'source' => 'bulk',
                ]),
            };

            $this->eventService->record($application, 'bulk_document_status', 'Массовое изменение документа', "Документ {$type->name} переведен в статус {$status}.", ['document_type' => $typeCode, 'status' => $status]);
        }

        return ['type' => 'changed', 'changes' => ['document_type' => $typeCode, 'status' => $status]];
    }

    private function markRecommended(ApplicantApplication $application, bool $apply): array
    {
        if ($application->recommended_for_enrollment) {
            return ['type' => 'skipped', 'reason' => 'Абитуриент уже рекомендован.', 'changes' => ['recommended_for_enrollment' => true]];
        }
        if ($apply) {
            $application->update(['recommended_for_enrollment' => true]);
            $this->eventService->record($application, 'bulk_recommended', 'Рекомендован к зачислению', 'Отмечено массовой операцией.');
        }
        return ['type' => 'changed', 'changes' => ['recommended_for_enrollment' => true]];
    }

    private function assignProgram(ApplicantApplication $application, array $payload, bool $apply): array
    {
        $changes = [];
        if (! empty($payload['education_program_id'])) {
            $changes['education_program_id'] = (int) $payload['education_program_id'];
        }
        if (array_key_exists('competition_name', $payload)) {
            $changes['competition_name'] = trim((string) $payload['competition_name']) ?: null;
        }
        if ($changes === []) {
            return ['type' => 'error', 'reason' => 'Не указана программа или конкурс.'];
        }
        if ($apply) {
            $application->update($changes);
            $this->eventService->record($application, 'bulk_assigned', 'Назначено направление', 'Программа или конкурс изменены массовой операцией.', $changes);
        }
        return ['type' => 'changed', 'changes' => $changes];
    }

    private function enroll(ApplicantApplication $application, array $payload, bool $apply): array
    {
        $groupId = (int) ($payload['group_id'] ?? 0);
        $enrollmentDate = $payload['enrollment_date'] ?? now()->toDateString();

        if ($groupId <= 0) {
            return ['type' => 'error', 'reason' => 'Не указана группа для зачисления.'];
        }
        if ($application->status === 'enrolled') {
            return ['type' => 'skipped', 'reason' => 'Заявление уже зачислено.'];
        }

        $this->documentService->ensureDefaultDocuments($application);
        $documentStats = $this->documentService->completeness($application);
        if (! $documentStats['documents_complete']) {
            return ['type' => 'error', 'reason' => 'Получены не все обязательные документы.'];
        }
        if ($application->person_id && Student::where('person_id', $application->person_id)->exists()) {
            return ['type' => 'skipped', 'reason' => 'Для этой Person уже есть студент.'];
        }
        if ($application->email && Student::where('email', $application->email)->exists()) {
            return ['type' => 'skipped', 'reason' => 'Студент с таким email уже существует.'];
        }
        if ($application->birth_date && Student::query()
            ->where('last_name', $application->last_name)
            ->where('first_name', $application->first_name)
            ->where('birth_date', $application->birth_date)
            ->exists()) {
            return ['type' => 'skipped', 'reason' => 'Похожий студент уже существует.'];
        }

        $changes = ['group_id' => $groupId, 'status' => 'active', 'enrollment_date' => $enrollmentDate];

        if ($apply) {
            $personId = $application->person_id;
            if (! $personId) {
                $data = $this->personService->dataFromProfile($application);
                // Статус заявления — не статус человека: «accepted» и «recommended»
                // в `people` не значат ничего, а записались бы туда как есть.
                $data['status'] = 'active';

                $duplicates = $this->personService->findPossibleDuplicates($data);
                if ($duplicates->count() > 1) {
                    return ['type' => 'error', 'reason' => 'Найдено несколько возможных Person-дублей, требуется ручная проверка.'];
                }
                $person = $duplicates->first() ?: $this->personService->createPerson($data);
                $this->personService->linkProfile($application, $person);
                $personId = $person->id;
            }

            $student = Student::create([
                'person_id' => $personId,
                'group_id' => $groupId,
                'last_name' => $application->last_name,
                'first_name' => $application->first_name,
                'middle_name' => $application->middle_name,
                'birth_date' => $application->birth_date,
                'phone' => $application->phone,
                'email' => $application->email,
                'status' => 'active',
                'enrollment_date' => $enrollmentDate,
                'education_form' => $application->education_form,
                'funding_form' => $application->funding_form,
            ]);
            $application->update(['status' => 'enrolled']);
            $this->eventService->record($application, 'bulk_enrolled', 'Абитуриент зачислен', "Создана карточка студента #{$student->id}.", ['student_id' => $student->id, 'group_id' => $groupId]);
            $changes['student_id'] = $student->id;
            $changes['person_id'] = $personId;
        }

        return ['type' => 'changed', 'changes' => $changes];
    }

    private function export(Collection $applications): StreamedResponse
    {
        return CsvExport::download('admissions-selected.csv', ['id', 'fio', 'status', 'education_program_id', 'submitted_at', 'documents_provided', 'recommended_for_enrollment'], function (callable $row) use ($applications): void {
            foreach ($applications as $application) {
                $row([
                    $application->id,
                    $this->name($application),
                    $application->status,
                    $application->education_program_id,
                    $application->submitted_at?->toDateString(),
                    $application->documents_provided ? 'yes' : 'no',
                    $application->recommended_for_enrollment ? 'yes' : 'no',
                ]);
            }
        });
    }

    private function baseReport(string $action, int $total, array $selection = []): array
    {
        $scope = $selection['selection_scope'] ?? (empty($selection['ids'] ?? []) ? 'filter' : 'selected_ids');

        return [
            'action' => $action,
            'scope' => $scope,
            'scope_label' => match ($scope) {
                'current_page' => 'Текущая страница',
                'filter' => 'Все записи по фильтру',
                default => 'Выбранные записи',
            },
            'selected' => $total,
            'found' => $total,
            'will_change' => 0,
            'changed' => 0,
            'already_set' => 0,
            'skipped' => 0,
            'errors' => 0,
            'items' => [],
            'sample' => [],
        ];
    }

    private function appendResult(array &$report, ApplicantApplication $application, array $result): void
    {
        $type = $result['type'];
        if ($type === 'changed') { $report['will_change']++; $report['changed']++; }
        if ($type === 'already_set') { $report['already_set']++; $report['skipped']++; }
        if ($type === 'skipped') { $report['skipped']++; }
        if ($type === 'error') { $report['errors']++; }
        $item = ['id' => $application->id, 'name' => $this->name($application), 'result' => $type, 'reason' => $result['reason'] ?? null, 'changes' => $result['changes'] ?? []];
        $report['items'][] = $item;
        if (count($report['sample']) < 10) { $report['sample'][] = $item; }
    }

    private function name(ApplicantApplication $application): string
    {
        return trim(implode(' ', array_filter([$application->last_name, $application->first_name, $application->middle_name]))) ?: "Заявление #{$application->id}";
    }

    private function requestId(Request $request): string
    {
        return $request->header('X-Idempotency-Key') ?: $request->header('X-Request-ID') ?: (string) Str::uuid();
    }
}
