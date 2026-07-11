<?php

namespace App\Services;

use App\Models\AccessEvent;
use App\Models\ApplicantApplication;
use App\Models\ApplicantApplicationDocument;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Exam;
use App\Models\FisPackage;
use App\Models\FrdoPackage;
use App\Models\Graduate;
use App\Models\ReferenceItem;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeachingLoadItem;
use App\Services\ApplicantApplicationDocumentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

class DashboardAnalyticsService
{
    public function __construct(private readonly AttendanceAnalysisService $attendanceAnalysis)
    {
    }

    public function executive(): array
    {
        $today = now()->toDateString();
        $lastSevenDays = collect(range(6, 0))->map(fn (int $daysAgo) => now()->subDays($daysAgo)->toDateString());
        $accessToday = AccessEvent::query()->whereDate('event_time', $today);
        $latestAllowedByIdentity = AccessEvent::query()
            ->where('result', AccessEvent::RESULT_ALLOWED)
            ->whereNotNull('digital_identity_id')
            ->latest('event_time')
            ->get()
            ->unique('digital_identity_id');
        $frdoErrors = FrdoPackage::query()
            ->withCount('validationErrors')
            ->where(fn ($query) => $query->where('status', 'validation_failed')->orHas('validationErrors'))
            ->latest()
            ->take(5)
            ->get();
        $fisErrors = FisPackage::query()
            ->withCount('validationErrors')
            ->where(fn ($query) => $query->where('status', 'validation_failed')->orHas('validationErrors'))
            ->latest()
            ->take(5)
            ->get();
        $attendance = $this->attendanceAnalysis->dashboardSummary();
        $requiredDocumentsCount = ApplicantApplicationDocumentService::REQUIRED_DOCUMENTS_COUNT;
        $applicationsNoDocuments = ApplicantApplication::query()
            ->whereDoesntHave('documents', fn ($query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES))
            ->count();
        $applicationsIncompleteDocuments = ApplicantApplication::query()
            ->whereHas('documents', fn ($query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES), '>=', 1)
            ->whereHas('documents', fn ($query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES), '<', $requiredDocumentsCount)
            ->count();
        $applicationsCompleteDocuments = ApplicantApplication::query()
            ->whereHas('documents', fn ($query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES), '>=', $requiredDocumentsCount)
            ->count();
        $applicationsDocumentsConfirmed = ApplicantApplication::query()->where('documents_provided', true)->count();
        $verifiedCompleteDocuments = $this->verifiedCompleteApplicantCount();

        return [
            'data' => [
                'kpi' => [
                    'contingent' => [
                        'students_total' => Student::query()->count(),
                        'students_active' => Student::query()->where('status', 'active')->count(),
                        'graduates' => Graduate::query()->count(),
                        'applicants' => ApplicantApplication::query()->count(),
                    ],
                    'teachers' => [
                        'teachers_total' => Teacher::query()->count(),
                        'today_load_hours' => (int) TeachingLoadItem::query()->sum('hours_total'),
                        'absent_today' => 0,
                    ],
                    'learning' => [
                        'lessons_today' => ScheduleLesson::query()->whereDate('lesson_date', $today)->count(),
                        'exams_today' => Exam::query()->whereDate('exam_date', $today)->count(),
                        'free_classrooms' => $this->freeClassroomsToday($today),
                    ],
                    'access' => [
                        'inside_now' => $latestAllowedByIdentity->where('direction', AccessEvent::DIRECTION_IN)->count(),
                        'entries_today' => (clone $accessToday)->where('direction', AccessEvent::DIRECTION_IN)->where('result', AccessEvent::RESULT_ALLOWED)->count(),
                        'exits_today' => AccessEvent::query()->whereDate('event_time', $today)->where('direction', AccessEvent::DIRECTION_OUT)->where('result', AccessEvent::RESULT_ALLOWED)->count(),
                        'denied_today' => AccessEvent::query()->whereDate('event_time', $today)->where('result', AccessEvent::RESULT_DENIED)->count(),
                    ],
                    'attendance' => $attendance,
                    'admissions' => [
                        'new_applications' => ApplicantApplication::query()->where('status', 'new')->count(),
                        'pending_review' => ApplicantApplication::query()->whereIn('status', ['pending', 'in_review', 'documents_pending'])->count(),
                        'enrolled' => ApplicantApplication::query()->where('status', 'enrolled')->count(),
                        'no_documents' => $applicationsNoDocuments,
                        'incomplete_documents' => $applicationsIncompleteDocuments,
                        'complete_documents' => $applicationsCompleteDocuments,
                        'documents_confirmed' => $applicationsDocumentsConfirmed,
                        'without_passport' => $this->missingApplicantDocumentType('passport'),
                        'without_education_document' => $this->missingApplicantDocumentType('education_document'),
                        'without_personal_data_consent' => $this->missingApplicantDocumentType('personal_data_consent'),
                        'documents_under_review' => ApplicantApplicationDocument::query()->where('status', ApplicantApplicationDocument::STATUS_UNDER_REVIEW)->count(),
                        'documents_rejected' => ApplicantApplicationDocument::query()->where('status', ApplicantApplicationDocument::STATUS_REJECTED)->count(),
                        'verified_complete_documents' => $verifiedCompleteDocuments,
                    ],
                    'frdo' => [
                        'ready' => FrdoPackage::query()->where('status', 'ready')->count(),
                        'errors' => $frdoErrors->sum('validation_errors_count'),
                        'latest_packages' => FrdoPackage::query()->latest()->take(3)->get(['id', 'name', 'status', 'updated_at'])->map(fn ($package) => $this->packageSummary($package))->values(),
                    ],
                    'fis' => [
                        'admission_packages' => FisPackage::query()->where('package_type', 'admission')->count(),
                        'gia_packages' => FisPackage::query()->where('package_type', 'gia')->count(),
                        'errors' => $fisErrors->sum('validation_errors_count'),
                    ],
                    'system' => [
                        'version' => $this->versionInfo(),
                        'status' => 'ok',
                    ],
                ],
                'charts' => [
                    'applications_7_days' => $lastSevenDays->map(fn (string $date) => [
                        'date' => $date,
                        'value' => ApplicantApplication::query()->whereDate('submitted_at', $date)->count(),
                        'is_demo' => false,
                    ])->values(),
                    'access_7_days' => $lastSevenDays->map(fn (string $date) => [
                        'date' => $date,
                        'value' => AccessEvent::query()->whereDate('event_time', $date)->where('direction', AccessEvent::DIRECTION_IN)->count(),
                        'is_demo' => false,
                    ])->values(),
                    'lessons_7_days' => $lastSevenDays->map(fn (string $date) => [
                        'date' => $date,
                        'value' => ScheduleLesson::query()->whereDate('lesson_date', $date)->count(),
                        'is_demo' => false,
                    ])->values(),
                ],
                'attention' => $this->attentionItems($frdoErrors, $fisErrors, $attendance),
                'audit' => AuditLog::query()
                    ->with('user')
                    ->latest('created_at')
                    ->take(6)
                    ->get()
                    ->map(fn (AuditLog $log) => [
                        'id' => $log->id,
                        'title' => $log->action,
                        'description' => trim(implode(' · ', array_filter([$log->module, $log->entity_type]))),
                        'time' => $log->created_at?->format('d.m H:i'),
                        'user' => $log->user?->name,
                    ])->values(),
            ],
        ];
    }

    private function freeClassroomsToday(string $today): int
    {
        $busy = ScheduleLesson::query()->whereDate('lesson_date', $today)->whereNotNull('classroom_id')->distinct('classroom_id')->count('classroom_id');

        return max(0, Classroom::query()->count() - $busy);
    }

    private function attentionItems($frdoErrors, $fisErrors, array $attendance = []): array
    {
        $studentsWithoutPhoto = Student::query()->whereNull('photo_path')->count();
        $requiredDocumentsCount = ApplicantApplicationDocumentService::REQUIRED_DOCUMENTS_COUNT;
        $applicationsWithoutDocuments = ApplicantApplication::query()
            ->whereDoesntHave('documents', fn ($query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES))
            ->count();
        $applicationsIncompleteDocuments = ApplicantApplication::query()
            ->whereHas('documents', fn ($query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES), '>=', 1)
            ->whereHas('documents', fn ($query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES), '<', $requiredDocumentsCount)
            ->count();
        $applicationsCompleteDocuments = ApplicantApplication::query()
            ->whereHas('documents', fn ($query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES), '>=', $requiredDocumentsCount)
            ->count();
        $applicationsDocumentsConfirmed = ApplicantApplication::query()->where('documents_provided', true)->count();
        $applicationsVerifiedCompleteDocuments = $this->verifiedCompleteApplicantCount();
        $frdoErrorCount = $frdoErrors->sum('validation_errors_count');
        $fisErrorCount = $fisErrors->sum('validation_errors_count');

        $attendanceAttention = $attendance['attention'] ?? [];

        return collect([
            ['title' => 'Преподаватели не пришли', 'value' => $attendanceAttention['teachers_absent']['count'] ?? 0, 'tone' => ($attendanceAttention['teachers_absent']['count'] ?? 0) > 0 ? 'danger' : 'success', 'to' => '/attendance?type=teachers&status=absent'],
            ['title' => 'Преподаватели опоздали', 'value' => $attendanceAttention['teachers_late']['count'] ?? 0, 'tone' => ($attendanceAttention['teachers_late']['count'] ?? 0) > 0 ? 'warning' : 'success', 'to' => '/attendance?type=teachers&status=late'],
            ['title' => 'Студенты опоздали сверх порога', 'value' => $attendanceAttention['students_late_over_threshold']['count'] ?? 0, 'tone' => ($attendanceAttention['students_late_over_threshold']['count'] ?? 0) > 0 ? 'warning' : 'success', 'to' => '/attendance?type=students&status=late'],
            ['title' => 'Расписание без входа', 'value' => $attendanceAttention['schedule_without_entry']['count'] ?? 0, 'tone' => ($attendanceAttention['schedule_without_entry']['count'] ?? 0) > 0 ? 'danger' : 'success', 'to' => '/attendance?status=absent'],
            ['title' => 'Студенты без фото', 'value' => $studentsWithoutPhoto, 'tone' => $studentsWithoutPhoto > 0 ? 'warning' : 'success', 'to' => '/students'],
            ['title' => 'Заявления без документов', 'value' => $applicationsWithoutDocuments, 'tone' => $applicationsWithoutDocuments > 0 ? 'warning' : 'success', 'to' => '/admissions?documents=no_documents'],
            ['title' => 'Заявления с неполным комплектом', 'value' => $applicationsIncompleteDocuments, 'tone' => $applicationsIncompleteDocuments > 0 ? 'warning' : 'success', 'to' => '/admissions?documents=incomplete'],
            ['title' => 'Заявления с полным комплектом', 'value' => $applicationsCompleteDocuments, 'tone' => 'success', 'to' => '/admissions?documents=complete'],
            ['title' => 'Получение документов подтверждено', 'value' => $applicationsDocumentsConfirmed, 'tone' => 'neutral', 'to' => '/admissions'],
            ['title' => 'Документы ожидают проверки', 'value' => ApplicantApplicationDocument::query()->where('status', ApplicantApplicationDocument::STATUS_UNDER_REVIEW)->count(), 'tone' => 'warning', 'to' => '/admissions'],
            ['title' => 'Отклоненные документы', 'value' => ApplicantApplicationDocument::query()->where('status', ApplicantApplicationDocument::STATUS_REJECTED)->count(), 'tone' => 'danger', 'to' => '/admissions'],
            ['title' => 'Полностью подтвержденные комплекты', 'value' => $applicationsVerifiedCompleteDocuments, 'tone' => 'success', 'to' => '/admissions'],
            ['title' => 'Ошибки ФРДО', 'value' => $frdoErrorCount, 'tone' => $frdoErrorCount > 0 ? 'danger' : 'success', 'to' => '/frdo'],
            ['title' => 'Ошибки ФИС', 'value' => $fisErrorCount, 'tone' => $fisErrorCount > 0 ? 'danger' : 'success', 'to' => '/fis'],
        ])->values()->all();
    }

    private function missingApplicantDocumentType(string $code): int
    {
        return ApplicantApplication::query()
            ->whereDoesntHave('documents', fn ($query) => $query
                ->whereHas('documentType', fn ($query) => $query->where('code', $code))
                ->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES))
            ->count();
    }

    private function verifiedCompleteApplicantCount(): int
    {
        $requiredIds = ReferenceItem::query()
            ->whereHas('catalog', fn ($query) => $query->where('code', 'applicant_document_types'))
            ->where('is_active', true)
            ->get()
            ->filter(fn (ReferenceItem $item) => (bool) ($item->metadata['required'] ?? false))
            ->pluck('id')
            ->all();

        if ($requiredIds === []) {
            return 0;
        }

        return ApplicantApplication::query()
            ->whereDoesntHave('documents', fn ($query) => $query
                ->whereIn('document_type_id', $requiredIds)
                ->where('status', '!=', ApplicantApplicationDocument::STATUS_VERIFIED))
            ->whereHas('documents', fn ($query) => $query
                ->whereIn('document_type_id', $requiredIds)
                ->where('status', ApplicantApplicationDocument::STATUS_VERIFIED), '=', count($requiredIds))
            ->count();
    }

    private function packageSummary($package): array
    {
        return [
            'id' => $package->id,
            'name' => $package->name,
            'status' => $package->status,
            'updated_at' => $package->updated_at?->format('d.m.Y H:i'),
        ];
    }

    private function versionInfo(): array
    {
        $path = base_path('../frontend/public/version.json');

        if (File::exists($path)) {
            $payload = json_decode(File::get($path), true);

            if (is_array($payload)) {
                return $payload;
            }
        }

        return [
            'name' => 'CollegePortal',
            'version' => config('app.version', '0.7.0-dev'),
            'release' => 'Release 0.7',
            'build' => 'unknown',
            'buildDate' => now()->toDateString(),
            'environment' => app()->environment(),
        ];
    }
}
