<?php

namespace App\Services;

use App\Models\AccessEvent;
use App\Models\ApplicantApplication;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Exam;
use App\Models\FisPackage;
use App\Models\FrdoPackage;
use App\Models\Graduate;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeachingLoadItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

class DashboardAnalyticsService
{
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
                    'admissions' => [
                        'new_applications' => ApplicantApplication::query()->where('status', 'new')->count(),
                        'pending_review' => ApplicantApplication::query()->whereIn('status', ['pending', 'in_review', 'documents_pending'])->count(),
                        'enrolled' => ApplicantApplication::query()->where('status', 'enrolled')->count(),
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
                'attention' => $this->attentionItems($frdoErrors, $fisErrors),
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

    private function attentionItems($frdoErrors, $fisErrors): array
    {
        $studentsWithoutPhoto = Student::query()->whereNull('photo_path')->count();
        $applicationsWithoutDocuments = ApplicantApplication::query()->whereDoesntHave('documents', fn ($query) => $query->where('is_received', true))->count();
        $frdoErrorCount = $frdoErrors->sum('validation_errors_count');
        $fisErrorCount = $fisErrors->sum('validation_errors_count');

        return collect([
            ['title' => 'Студенты без фото', 'value' => $studentsWithoutPhoto, 'tone' => $studentsWithoutPhoto > 0 ? 'warning' : 'success', 'to' => '/students'],
            ['title' => 'Заявления без документов', 'value' => $applicationsWithoutDocuments, 'tone' => $applicationsWithoutDocuments > 0 ? 'warning' : 'success', 'to' => '/admissions'],
            ['title' => 'Ошибки ФРДО', 'value' => $frdoErrorCount, 'tone' => $frdoErrorCount > 0 ? 'danger' : 'success', 'to' => '/frdo'],
            ['title' => 'Ошибки ФИС', 'value' => $fisErrorCount, 'tone' => $fisErrorCount > 0 ? 'danger' : 'success', 'to' => '/fis'],
        ])->values()->all();
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
