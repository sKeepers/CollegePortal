<?php

namespace App\Services;

use App\Models\AccessEvent;
use App\Models\ApplicantApplication;
use App\Models\ApplicantApplicationDocument;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Employee;
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
use App\Support\Time\CollegeTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class DashboardAnalyticsService
{
    public function __construct(
        private readonly AttendanceAnalysisService $attendanceAnalysis,
        private readonly HrAbsenceService $hrAbsence,
        private readonly AccessPresenceService $presence,
    ) {
    }

    public function executive(): array
    {
        // Сутки колледжа, а не сутки сервера. Приложение живёт в UTC, и с
        // 21:00 UTC до полуночи `now()` называет вчерашнее число: рабочий стол
        // в это время показывал бы «сегодня» за уже прошедший день.
        $today = CollegeTime::todayDate();
        $lastSevenDays = collect(range(6, 0))->map(
            fn (int $daysAgo) => Carbon::parse($today)->subDays($daysAgo)->toDateString(),
        );
        // `whereDate` здесь не годится: у `event_time` тип `timestamp`, и
        // `whereDate` сравнил бы его с UTC-сутками. Для колонок `date`
        // (`lesson_date`, `exam_date`) наоборот — там `whereDate` верен, и
        // перевод в пояс их сломал бы.
        $accessToday = AccessEvent::query()->whereBetween('event_time', CollegeTime::dayRange($today));
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
        $applicationsNoDocuments = $this->legacyApplicantApplications()
            ->whereDoesntHave('documents', fn ($query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES))
            ->count();
        $applicationsIncompleteDocuments = $this->legacyApplicantApplications()
            ->whereHas('documents', fn ($query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES), '>=', 1)
            ->whereHas('documents', fn ($query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES), '<', $requiredDocumentsCount)
            ->count();
        $applicationsCompleteDocuments = $this->legacyApplicantApplications()
            ->whereHas('documents', fn ($query) => $query->whereIn('status', ApplicantApplicationDocument::COMPLETE_STATUSES), '>=', $requiredDocumentsCount)
            ->count();
        $applicationsDocumentsConfirmed = $this->legacyApplicantApplications()->where('documents_provided', true)->count();
        $verifiedCompleteDocuments = $this->verifiedCompleteApplicantCount();
        // Те же семь чисел нужны и сводке, и блоку «Требует внимания». Считаем
        // их здесь один раз: пока блок считал их заново, одно открытие
        // рабочего стола спрашивало `applicant_applications` восемнадцать раз
        // вместо тринадцати, а самый тяжёлый из счётчиков — с двумя
        // подзапросами по документам — выполнялся дважды.
        $applicationDocuments = [
            'no_documents' => $applicationsNoDocuments,
            'incomplete_documents' => $applicationsIncompleteDocuments,
            'complete_documents' => $applicationsCompleteDocuments,
            'documents_confirmed' => $applicationsDocumentsConfirmed,
            'verified_complete_documents' => $verifiedCompleteDocuments,
            'documents_under_review' => ApplicantApplicationDocument::query()->where('status', ApplicantApplicationDocument::STATUS_UNDER_REVIEW)->count(),
            'documents_rejected' => ApplicantApplicationDocument::query()->where('status', ApplicantApplicationDocument::STATUS_REJECTED)->count(),
        ];

        return [
            'data' => [
                'kpi' => [
                    'contingent' => [
                        'students_total' => Student::query()->count(),
                        'students_active' => Student::query()->where('status', 'active')->count(),
                        'graduates' => Graduate::query()->count(),
                        'applicants' => $this->legacyApplicantApplications()->count(),
                    ],
                    'teachers' => [
                        'teachers_total' => Teacher::query()->count(),
                        'today_load_hours' => (int) TeachingLoadItem::query()->sum('hours_total'),
                        'absent_today' => 0,
                    ],
                    'hr' => [
                        'employees_total' => Employee::query()->count(),
                        'employees_active' => Employee::query()->whereNull('dismissed_at')->where('status', '!=', 'dismissed')->count(),
                        'employees_unavailable' => Employee::query()->whereIn('status', Employee::UNAVAILABLE_STATUSES)->count(),
                        'employees_dismissed' => Employee::query()->where(fn ($query) => $query->whereNotNull('dismissed_at')->orWhere('status', 'dismissed'))->count(),
                        'absence_calendar' => $this->hrAbsence->dashboardKpi(),
                    ],
                    'learning' => [
                        'lessons_today' => ScheduleLesson::query()->whereDate('lesson_date', $today)->count(),
                        'exams_today' => Exam::query()->whereDate('exam_date', $today)->count(),
                        'free_classrooms' => $this->freeClassroomsToday($today),
                    ],
                    'access' => [
                        'inside_now' => $this->presence->insideNowCount(),
                        'entries_today' => (clone $accessToday)->where('direction', AccessEvent::DIRECTION_IN)->where('result', AccessEvent::RESULT_ALLOWED)->count(),
                        'exits_today' => (clone $accessToday)->where('direction', AccessEvent::DIRECTION_OUT)->where('result', AccessEvent::RESULT_ALLOWED)->count(),
                        'denied_today' => (clone $accessToday)->where('result', AccessEvent::RESULT_DENIED)->count(),
                    ],
                    'attendance' => $attendance,
                    'admissions' => [
                        'new_applications' => $this->legacyApplicantApplications()->where('status', 'new')->count(),
                        'pending_review' => $this->legacyApplicantApplications()->whereIn('status', ['pending', 'in_review', 'documents_pending'])->count(),
                        'enrolled' => $this->legacyApplicantApplications()->where('status', 'enrolled')->count(),
                        'no_documents' => $applicationsNoDocuments,
                        'incomplete_documents' => $applicationsIncompleteDocuments,
                        'complete_documents' => $applicationsCompleteDocuments,
                        'documents_confirmed' => $applicationsDocumentsConfirmed,
                        'without_passport' => $this->missingApplicantDocumentType('passport'),
                        'without_education_document' => $this->missingApplicantDocumentType('education_document'),
                        'without_personal_data_consent' => $this->missingApplicantDocumentType('personal_data_consent'),
                        'documents_under_review' => $applicationDocuments['documents_under_review'],
                        'documents_rejected' => $applicationDocuments['documents_rejected'],
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
                    'applications_7_days' => $this->dailySeries(
                        $this->legacyApplicantApplications(), 'submitted_at', $lastSevenDays,
                    ),
                    'access_7_days' => $this->dailySeries(
                        AccessEvent::query()->where('direction', AccessEvent::DIRECTION_IN), 'event_time', $lastSevenDays,
                        columnCarriesTime: true,
                    ),
                    'lessons_7_days' => $this->dailySeries(
                        ScheduleLesson::query(), 'lesson_date', $lastSevenDays,
                    ),
                ],
                'attention' => $this->attentionItems($frdoErrors, $fisErrors, $attendance, $applicationDocuments),
                'audit' => AuditLog::query()
                    ->with('user')
                    ->latest('created_at')
                    ->take(6)
                    ->get()
                    ->map(fn (AuditLog $log) => [
                        'id' => $log->id,
                        'title' => $log->action,
                        'description' => trim(implode(' · ', array_filter([$log->module, $log->entity_type]))),
                        // Час рисуется здесь, на сервере, поэтому пояс надо задать явно: без
                        // него виджет показывал UTC рядом с журналом действий, который
                        // показывает время колледжа, — одно событие, два часа на одном
                        // экране. Замерено 03.09.2026: 18:22 в виджете против 21:22 в журнале.
                        'time' => CollegeTime::forDisplay($log->created_at)?->format('d.m H:i'),
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

    /**
     * Счётчики документов заявлений приходят готовыми из `executive()` — те же
     * числа уже посчитаны для сводки, и второй раз их спрашивать незачем.
     *
     * @param  array<string, int>  $applications
     */
    private function attentionItems($frdoErrors, $fisErrors, array $attendance, array $applications): array
    {
        $studentsWithoutPhoto = Student::query()->whereNull('photo_path')->count();
        $applicationsWithoutDocuments = $applications['no_documents'];
        $applicationsIncompleteDocuments = $applications['incomplete_documents'];
        $applicationsCompleteDocuments = $applications['complete_documents'];
        $applicationsDocumentsConfirmed = $applications['documents_confirmed'];
        $applicationsVerifiedCompleteDocuments = $applications['verified_complete_documents'];
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
            ['title' => 'Документы ожидают проверки', 'value' => $applications['documents_under_review'], 'tone' => 'warning', 'to' => '/admissions'],
            ['title' => 'Отклоненные документы', 'value' => $applications['documents_rejected'], 'tone' => 'danger', 'to' => '/admissions'],
            ['title' => 'Полностью подтвержденные комплекты', 'value' => $applicationsVerifiedCompleteDocuments, 'tone' => 'success', 'to' => '/admissions'],
            ['title' => 'Ошибки ФРДО', 'value' => $frdoErrorCount, 'tone' => $frdoErrorCount > 0 ? 'danger' : 'success', 'to' => '/frdo'],
            ['title' => 'Ошибки ФИС', 'value' => $fisErrorCount, 'tone' => $fisErrorCount > 0 ? 'danger' : 'success', 'to' => '/fis'],
        ])->values()->all();
    }

    private function missingApplicantDocumentType(string $code): int
    {
        return $this->legacyApplicantApplications()
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

        return $this->legacyApplicantApplications()
            ->whereDoesntHave('documents', fn ($query) => $query
                ->whereIn('document_type_id', $requiredIds)
                ->where('status', '!=', ApplicantApplicationDocument::STATUS_VERIFIED))
            ->whereHas('documents', fn ($query) => $query
                ->whereIn('document_type_id', $requiredIds)
                ->where('status', ApplicantApplicationDocument::STATUS_VERIFIED), '=', count($requiredIds))
            ->count();
    }

    /**
     * Ряд «по дню за последнюю неделю» одним запросом, а не семью.
     *
     * Раньше каждый день считался отдельным `count()`, и три ряда стоили
     * двадцать один запрос из девяноста трёх на открытие рабочего стола
     * директора (замер 23.08.2026 на 593 студентах). События проходной
     * копятся каждый день, и дальше было бы хуже.
     *
     * Чем считается «день» и почему — в `dayExpression()` ниже.
     *
     * Дни, за которые записей нет, база не вернёт вовсе — поэтому ряд
     * собирается по списку дат, а не по ответу: график обязан иметь семь
     * точек, включая нулевые.
     *
     * `$columnCarriesTime` разводит **два разных вида колонок**, и разница
     * здесь не косметическая. У колонки типа `date` пояса нет вовсе, и перевод
     * её в пояс колледжа сдвинул бы ряд на сутки — то есть сломал бы верное;
     * у колонки `timestamp` день считается по UTC, и без перевода ряд теряет
     * первые три часа каждых суток. Замерено по схеме стенда 28.08.2026:
     * `applicant_applications.submitted_at` и `schedule_lessons.lesson_date` —
     * `date`, `access_events.event_time` — `timestamp`. Поэтому из трёх рядов
     * рабочего стола перевода требует ровно один.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $dates
     * @return \Illuminate\Support\Collection<int, array{date: string, value: int, is_demo: bool}>
     */
    private function dailySeries(Builder $query, string $column, Collection $dates, bool $columnCarriesTime = false): Collection
    {
        $grammar = $query->getQuery()->getGrammar();

        // Нижняя граница без времени намеренно. В SQLite дата хранится строкой,
        // и сравнение тоже строковое: '2026-08-17' < '2026-08-17 00:00:00',
        // поэтому занятия первого дня выпали бы из ряда.
        //
        // У колонки со временем границы другие: там нужен отрезок UTC, который
        // соответствует семи суткам колледжа, иначе крайние дни ряда обрежутся
        // по три часа.
        $range = $columnCarriesTime
            ? [CollegeTime::dayStart($dates->first()), CollegeTime::dayEnd($dates->last())]
            : [$dates->first(), $dates->last().' 23:59:59'];

        $counts = $query
            ->whereBetween($column, $range)
            ->selectRaw($this->dayExpression($grammar, $column, $columnCarriesTime).' as day, count(*) as total')
            ->groupBy('day')
            // `toBase()->get()`, а не `pluck()`: `pluck` на построителе подменяет
            // список выбираемых полей своими двумя, и `selectRaw` вместе с ним
            // пропал бы. `toBase()` заодно избавляет от сборки моделей из двух
            // чужих колонок — нужны строки, а не сущности.
            ->toBase()
            ->get()
            ->pluck('total', 'day');

        return $dates->map(fn (string $date) => [
            'date' => $date,
            'value' => (int) ($counts[$date] ?? 0),
            'is_demo' => false,
        ])->values();
    }

    /**
     * Выражение «какой это день», по которому идёт группировка ряда.
     *
     * `date(...)` выбрано намеренно и остаётся: оно есть и в PostgreSQL, и в
     * SQLite, а `CAST(... AS date)` в SQLite молча даёт не дату.
     *
     * Перевод в пояс приходится писать на двух диалектах, потому что SQLite
     * именованных поясов не знает. В PostgreSQL — точный перевод по имени
     * пояса; в SQLite — смещение числом, и число берётся **у самого пояса**, а
     * не переписывается сюда руками: два места с одним смещением разошлись бы
     * молча, и оба выглядели бы рабочими.
     *
     * Оговорка, которую видно только здесь: смещение для SQLite берётся на
     * момент запроса и применяется ко всем семи дням ряда. Для `Europe/Moscow`
     * это ничего не меняет — перевода часов там нет с 2014 года, — но если
     * пояс когда-нибудь сменят на переводящий, крайние дни ряда на границе
     * перевода сойдутся неточно. В бою это не срабатывает: PROD на PostgreSQL,
     * где перевод точный, а SQLite остаётся connection по умолчанию в тестах.
     */
    private function dayExpression(Grammar $grammar, string $column, bool $columnCarriesTime): string
    {
        $wrapped = $grammar->wrap($column);

        if (! $columnCarriesTime) {
            return 'date('.$wrapped.')';
        }

        if ($grammar instanceof PostgresGrammar) {
            return 'date('.$wrapped." AT TIME ZONE 'UTC' AT TIME ZONE '".CollegeTime::ZONE."')";
        }

        $minutes = (int) (Carbon::now(CollegeTime::ZONE)->getOffset() / 60);

        return 'date('.$wrapped.", '".sprintf('%+d', $minutes)." minutes')";
    }

    private function legacyApplicantApplications(): Builder
    {
        return ApplicantApplication::query()->legacy();
    }

    private function packageSummary($package): array
    {
        return [
            'id' => $package->id,
            'name' => $package->name,
            'status' => $package->status,
            'updated_at' => CollegeTime::forDisplay($package->updated_at)?->format('d.m.Y H:i'),
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
            'version' => config('app.version', env('APP_VERSION', '0.8.0-rc2')),
            'release' => env('APP_RELEASE', 'v0.8.0-rc2'),
            'build' => 'unknown',
            'gitCommit' => 'unknown',
            'buildDate' => now()->toDateString(),
            'environment' => app()->environment(),
            'frontendStack' => 'Vue 3 + Quasar + Vite',
            'backendStack' => 'Laravel 12 + PHP '.PHP_VERSION,
            'apiVersion' => 'v1',
        ];
    }
}
