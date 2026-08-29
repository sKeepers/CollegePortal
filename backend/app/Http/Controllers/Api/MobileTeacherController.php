<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DigitalIdentityResource;
use App\Http\Resources\ScheduleLessonResource;
use App\Http\Resources\TeacherResource;
use App\Models\DigitalIdentity;
use App\Models\JournalLesson;
use App\Models\ScheduleLesson;
use App\Models\Teacher;
use App\Models\User;
use App\Services\QrSvgService;
use App\Support\Time\CollegeTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Мобильный кабинет преподавателя: свои занятия на день и на неделю, состояние
 * журнала по каждому занятию и свой QR-пропуск.
 *
 * Преподаватель определяется только по `teachers.user_id` вошедшего. Параметра
 * `teacher_id` здесь нет намеренно: подставлять нечего, и чужой день не
 * открывается ни при каком праве.
 *
 * Отметку посещаемости и оценки кабинет не пишет — для этого он зовёт
 * существующие маршруты журнала (`journal/lessons/{lesson}/attendance` и
 * `.../grades`), которые уже проверяют, что занятие принадлежит этому
 * преподавателю. Второй путь записи означал бы вторую реализацию правила «кто
 * имеет право отметить».
 */
class MobileTeacherController extends Controller
{
    public function show(Request $request, QrSvgService $qrSvgService): array
    {
        $user = $request->user();
        $scheduleDate = $this->scheduleDate($request);

        /** @var Teacher|null $teacher */
        $teacher = $user->teacher()->first();

        // Отсутствие карточки преподавателя — не отказ в доступе: кабинет
        // открывается пустым с объяснением. Правило портала, оно же в
        // DigitalIdentityController и TeachingLoadController.
        if ($teacher === null) {
            return ['data' => $this->emptyCabinet($scheduleDate, 'Текущий пользователь не связан с карточкой преподавателя.')];
        }

        $lessons = ScheduleLesson::query()
            ->with(['group', 'subject', 'classroom'])
            ->where('teacher_id', $teacher->id)
            ->whereDate('lesson_date', $scheduleDate)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $journal = $this->journalStateFor($teacher, $lessons);
        $abilities = $this->abilities($user);

        // `resolve`, а не `toArray`: только он отбрасывает незагруженные связи.
        // При `toArray` в ответе остаются объекты MissingValue, и сериализация
        // падает на пустой связи преподавателя пятисоткой.
        $payloadLessons = $lessons
            ->map(fn (ScheduleLesson $lesson): array => (new ScheduleLessonResource($lesson))->resolve($request)
                + ['journal' => $this->lessonJournal($journal->get($lesson->id), $abilities)])
            ->all();

        $digitalIdentity = $this->activePass($teacher);
        $dynamicQr = $digitalIdentity ? $qrSvgService->dynamicPayload($digitalIdentity) : null;

        return [
            'data' => [
                'teacher' => new TeacherResource($teacher),
                'message' => '',
                'schedule_date' => $scheduleDate->toDateString(),
                'lessons' => $payloadLessons,
                'next_lesson' => $this->nextLesson($lessons, $scheduleDate)?->id,
                'week' => $this->week($teacher, $scheduleDate),
                'day_summary' => $this->daySummary($payloadLessons),
                'abilities' => $abilities,
                'digital_identity' => $digitalIdentity ? new DigitalIdentityResource($digitalIdentity) : null,
                'qr_svg' => $dynamicQr ? $qrSvgService->renderSvg($dynamicQr['payload']) : null,
                'qr_expires_at' => $dynamicQr ? $dynamicQr['expires_at']->toIso8601String() : null,
                'qr_refresh_seconds' => QrSvgService::DYNAMIC_TTL_SECONDS,
            ],
        ];
    }

    /**
     * Состояние журнала по занятиям дня: одним запросом, а не по занятию.
     *
     * @param  Collection<int, ScheduleLesson>  $lessons
     * @return Collection<int, JournalLesson>
     */
    private function journalStateFor(Teacher $teacher, Collection $lessons): Collection
    {
        if ($lessons->isEmpty()) {
            return collect();
        }

        // Журнал занятия заводится по записи расписания (`schedule_entry_id`), а в день
        // преподавателя занятия приходят зеркалом в `schedule_lessons`. Искать только по
        // старой связи значило показывать «журнал не открыт» на занятии, где журнал открыт
        // и отметки уже стоят, — и звать открыть его ещё раз.
        $entryIds = $lessons->pluck('schedule_entry_id')->filter()->values()->all();
        $byEntry = $lessons->whereNotNull('schedule_entry_id')->keyBy('schedule_entry_id');

        return JournalLesson::query()
            ->where('teacher_id', $teacher->id)
            ->where(function (Builder $query) use ($lessons, $entryIds): void {
                $query->whereIn('legacy_schedule_lesson_id', $lessons->pluck('id')->all());

                if ($entryIds !== []) {
                    $query->orWhereIn('schedule_entry_id', $entryIds);
                }
            })
            ->withCount([
                'attendance as students_count',
                // Строки со `source = roster` создаёт сам журнал при открытии
                // занятия, преподаватель их ещё не трогал. Отмеченными считаем
                // только те, что он поставил руками.
                'attendance as marked_count' => fn (Builder $query) => $query->where('source', '!=', 'roster'),
                'grades as grades_count' => fn (Builder $query) => $query->whereNotNull('value'),
            ])
            ->get()
            // Ключ — занятие расписания в том виде, в каком его знает день преподавателя:
            // журнал, открытый по записи, приводится к своему зеркалу.
            ->filter(fn (JournalLesson $lesson): bool => $lesson->legacy_schedule_lesson_id !== null
                || $byEntry->has($lesson->schedule_entry_id))
            ->keyBy(fn (JournalLesson $lesson): int => $lesson->legacy_schedule_lesson_id
                ?? $byEntry->get($lesson->schedule_entry_id)->id);
    }

    /**
     * @param  array<string, bool>  $abilities
     * @return array<string, mixed>
     */
    private function lessonJournal(?JournalLesson $lesson, array $abilities): array
    {
        if ($lesson === null) {
            return [
                'lesson_id' => null,
                'status' => null,
                'students' => 0,
                'marked' => 0,
                'grades' => 0,
                'is_signed' => false,
                'can_open' => $abilities['open_journal'],
                'can_mark_attendance' => false,
                'can_set_grades' => false,
            ];
        }

        $signed = $lesson->isSigned();

        return [
            'lesson_id' => $lesson->id,
            'status' => $lesson->status,
            'students' => (int) $lesson->students_count,
            'marked' => (int) $lesson->marked_count,
            'grades' => (int) $lesson->grades_count,
            'is_signed' => $signed,
            'can_open' => false,
            // Подписанное занятие правится только после переоткрытия, а
            // переоткрывает не преподаватель. Кнопка, которая всё равно не
            // сработает, не показывается.
            'can_mark_attendance' => $abilities['mark_attendance'] && ! $signed,
            'can_set_grades' => $abilities['set_grades'] && ! $signed,
        ];
    }

    /** @return array<string, bool> */
    private function abilities(User $user): array
    {
        return [
            'open_journal' => $user->hasPermission('journal.edit'),
            'mark_attendance' => $user->hasPermission('journal.attendance'),
            'set_grades' => $user->hasPermission('journal.grades'),
        ];
    }

    /**
     * @param  Collection<int, ScheduleLesson>  $lessons
     */
    private function nextLesson(Collection $lessons, Carbon $scheduleDate): ?ScheduleLesson
    {
        if (! $scheduleDate->isToday()) {
            return $lessons->first();
        }

        $now = Carbon::now();

        return $lessons->first(function (ScheduleLesson $lesson) use ($now): bool {
            if ($lesson->starts_at === null) {
                return false;
            }

            return CollegeTime::moment($lesson->lesson_date, $lesson->starts_at->format('H:i'))
                ->greaterThanOrEqualTo($now);
        }) ?? $lessons->first();
    }

    /**
     * Неделя, в которую попадает выбранный день: семь дат с числом занятий.
     * Подписи дней — дело интерфейса, отсюда уходят только даты и счётчики.
     *
     * @return list<array<string, mixed>>
     */
    private function week(Teacher $teacher, Carbon $scheduleDate): array
    {
        $start = $scheduleDate->copy()->startOfWeek();
        $end = $scheduleDate->copy()->endOfWeek();

        $rows = ScheduleLesson::query()
            ->where('teacher_id', $teacher->id)
            ->whereBetween('lesson_date', [$start->toDateString(), $end->toDateString()])
            ->get(['id', 'lesson_date']);

        // Счёт по датам собирается в PHP намеренно: `groupBy('lesson_date')`
        // отдаёт ключ в формате базы, и PostgreSQL с SQLite здесь расходятся.
        $counts = [];
        foreach ($rows as $row) {
            $key = Carbon::parse($row->lesson_date)->toDateString();
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $week = [];
        for ($day = $start->copy(); $day->lessThanOrEqualTo($end); $day->addDay()) {
            $date = $day->toDateString();
            $week[] = [
                'date' => $date,
                'lessons' => $counts[$date] ?? 0,
                'is_today' => $day->isToday(),
                'is_selected' => $date === $scheduleDate->toDateString(),
            ];
        }

        return $week;
    }

    /**
     * @param  list<array<string, mixed>>  $lessons
     * @return array<string, int>
     */
    private function daySummary(array $lessons): array
    {
        $summary = ['lessons' => count($lessons), 'journals_opened' => 0, 'students' => 0, 'marked' => 0];

        foreach ($lessons as $lesson) {
            $journal = $lesson['journal'];
            if ($journal['lesson_id'] !== null) {
                $summary['journals_opened']++;
            }
            $summary['students'] += $journal['students'];
            $summary['marked'] += $journal['marked'];
        }

        return $summary;
    }

    private function activePass(Teacher $teacher): ?DigitalIdentity
    {
        return DigitalIdentity::query()
            ->where('entity_type', DigitalIdentity::ENTITY_TEACHER)
            ->where('entity_id', $teacher->id)
            ->where('status', DigitalIdentity::STATUS_ACTIVE)
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->latest('issued_at')
            ->first();
    }

    /** @return array<string, mixed> */
    private function emptyCabinet(Carbon $scheduleDate, string $message): array
    {
        return [
            'teacher' => null,
            'message' => $message,
            'schedule_date' => $scheduleDate->toDateString(),
            'lessons' => [],
            'next_lesson' => null,
            'week' => [],
            'day_summary' => ['lessons' => 0, 'journals_opened' => 0, 'students' => 0, 'marked' => 0],
            'abilities' => ['open_journal' => false, 'mark_attendance' => false, 'set_grades' => false],
            'digital_identity' => null,
            'qr_svg' => null,
            'qr_expires_at' => null,
            'qr_refresh_seconds' => QrSvgService::DYNAMIC_TTL_SECONDS,
        ];
    }

    private function scheduleDate(Request $request): Carbon
    {
        $value = $request->query('date');

        if ($value === null || $value === '') {
            return Carbon::today();
        }

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            abort(422, 'Дата расписания должна быть передана в формате ГГГГ-ММ-ДД.');
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Exception) {
            abort(422, 'Дата расписания некорректна.');
        }
    }
}
