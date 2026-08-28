<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeacherResource;
use App\Models\AccessEvent;
use App\Models\Group;
use App\Models\JournalAttendance;
use App\Models\JournalGrade;
use App\Models\JournalLesson;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\AttendanceAnalysisService;
use App\Services\CuratorScopeService;
use App\Services\GroupRosterService;
use App\Support\Time\CollegeTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Мобильный кабинет куратора: закреплённые группы, посещаемость за день и
 * неделю, список студентов с контактами, события проходной по своей группе.
 *
 * Разграничение здесь важнее всего остального в кабинете: куратор видит
 * телефоны и почту студентов. Право `mobile.curator.view` открывает раздел
 * **всем** кураторам сразу и поэтому ничего не разграничивает — чья группа,
 * решает `groups.curator_id`, и решает это сервер на каждом запросе, а не один
 * раз при входе.
 *
 * Идентификатор группы приходит в пути, и подстановка чужого — первое, что
 * попробует любопытный. Поэтому единственная точка входа к группе —
 * `authorizeGroup`: она есть в каждом методе, а `MobileCuratorAccessTest`
 * перебирает маршруты кабинета списком, чтобы новый маршрут не проскочил мимо
 * проверки молча.
 *
 * Посещаемость не считается заново: её считает `AttendanceAnalysisService`,
 * которому группа подставляется принудительно. Ходить за ней в общие
 * `api/attendance/*` нельзя — там `group_id` берётся из запроса как есть.
 */
class MobileCuratorController extends Controller
{
    /** Сколько событий проходной показывает экран телефона. */
    private const EVENT_LIMIT = 100;

    public function __construct(
        private readonly AttendanceAnalysisService $analysis,
        private readonly CuratorScopeService $scope,
        private readonly GroupRosterService $roster,
    ) {
    }

    /** Кабинет: кто я и какие группы за мной закреплены. */
    public function index(Request $request): array
    {
        $curator = $this->curator($request);

        if ($curator === null) {
            return ['data' => [
                'curator' => null,
                'message' => 'Текущий пользователь не связан с карточкой преподавателя.',
                'groups' => [],
            ]];
        }

        $groups = $this->groupsOf($request);

        return ['data' => [
            'curator' => new TeacherResource($curator),
            'message' => $groups->isEmpty() ? 'За вами не закреплено ни одной группы.' : '',
            'groups' => $groups->map(fn (Group $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'course' => $group->course,
                'specialty' => $group->specialty,
                'students_count' => (int) $group->students_count,
            ])->values()->all(),
        ]];
    }

    /** Карточка группы: студенты с контактами и сводка за выбранный день. */
    public function group(Request $request, Group $group): array
    {
        $this->authorizeGroup($request, $group);
        $date = $this->date($request);

        $students = $this->studentsOf($group);
        $analysis = $this->analysis->students([
            'group_id' => $group->id,
            'date_from' => $date->toDateString(),
            'date_to' => $date->toDateString(),
        ]);

        return ['data' => [
            'group' => $this->groupPayload($group, $students->count()),
            'date' => $date->toDateString(),
            // Список узкий намеренно: экран показывает ФИО и контакт, и
            // отдавать вместе с ними всю учебную карточку незачем.
            'students' => $students->map(fn (Student $student): array => [
                'id' => $student->id,
                'last_name' => $student->last_name,
                'first_name' => $student->first_name,
                'middle_name' => $student->middle_name,
                'full_name' => $this->fullName($student),
                'phone' => $student->phone,
                'email' => $student->email,
                'status' => $student->status,
            ])->values()->all(),
            'summary' => $analysis['summary'],
        ]];
    }

    /** Посещаемость группы: день или неделя, в которую этот день попадает. */
    public function attendance(Request $request, Group $group): array
    {
        $this->authorizeGroup($request, $group);
        $date = $this->date($request);
        $range = $request->query('range') === 'week' ? 'week' : 'day';

        $from = $range === 'week' ? $date->copy()->startOfWeek() : $date;
        $to = $range === 'week' ? $date->copy()->endOfWeek() : $date;

        $analysis = $this->analysis->students([
            'group_id' => $group->id,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
        ]);

        return ['data' => [
            'group' => $this->groupPayload($group, null),
            'range' => $range,
            'date' => $date->toDateString(),
            'date_from' => $analysis['date_from'],
            'date_to' => $analysis['date_to'],
            'summary' => $analysis['summary'],
            'rows' => $analysis['data'],
        ]];
    }

    /** Проходная: события своей группы за выбранный день. */
    public function access(Request $request, Group $group): array
    {
        $this->authorizeGroup($request, $group);
        $date = $this->date($request);
        $students = $this->studentsOf($group);
        $names = $students->mapWithKeys(fn (Student $student): array => [$student->id => $this->fullName($student)]);

        $query = AccessEvent::query()
            ->with(['accessPoint.building'])
            ->where('entity_type', 'student')
            // Пустой список должен давать ноль событий, а не потерянный фильтр:
            // `whereIn` с пустым массивом так и делает, и это здесь важно —
            // группа без студентов не должна показать проходную всего колледжа.
            ->whereIn('entity_id', $students->pluck('id')->all())
            ->whereBetween('event_time', [CollegeTime::dayStart($date), CollegeTime::dayEnd($date)]);

        $total = (clone $query)->count();
        $events = $query->orderByDesc('event_time')->limit(self::EVENT_LIMIT)->get();

        return ['data' => [
            'group' => $this->groupPayload($group, $students->count()),
            'date' => $date->toDateString(),
            'total' => $total,
            'limit' => self::EVENT_LIMIT,
            'truncated' => $total > self::EVENT_LIMIT,
            'events' => $events->map(fn (AccessEvent $event): array => [
                'id' => $event->id,
                'entity_id' => $event->entity_id,
                'full_name' => $names->get($event->entity_id, 'Студент'),
                'direction' => $event->direction,
                'result' => $event->result,
                'event_time' => $event->event_time?->toIso8601String(),
                'access_point' => $event->accessPoint?->name,
                'building' => $event->accessPoint?->building?->name,
            ])->values()->all(),
        ]];
    }

    private function curator(Request $request): ?Teacher
    {
        return $request->user()->teacher()->first();
    }

    /**
     * Группы куратора. Считает `CuratorScopeService` — тем же кодом, которым их
     * считают журнал и раздел успеваемости: три ответа на вопрос «чья это
     * группа» однажды разойдутся.
     *
     * @return Collection<int, Group>
     */
    private function groupsOf(Request $request): Collection
    {
        $groupIds = $this->scope->curatedGroupIds($request->user());

        if ($groupIds->isEmpty()) {
            return collect();
        }

        return Group::query()
            ->whereIn('id', $groupIds->all())
            ->withCount('students')
            ->orderBy('name')
            ->get();
    }

    /**
     * Единственный вход к чужой группе — через эту проверку.
     *
     * Отсутствие карточки преподавателя даёт пустой кабинет, но не доступ:
     * поэтому здесь `403`, а не «нечего проверять, пропустим».
     */
    /**
     * Успеваемость и пропуски по журналу за период.
     *
     * В кабинете куратора этого не было вовсе: были состав, проходная и
     * посещаемость, а посещаемость там — **проходная**, то есть был ли проход
     * через турникет. С куратора же спрашивают учёбу: кто пропускает занятия и
     * кто скатился по оценкам. Ответа на это в телефоне не было ни в каком виде.
     *
     * Считается по журналу, а не по проходной, и потому расходится с соседним
     * экраном намеренно: там физическое присутствие, здесь — отметка
     * преподавателя. Заготовки, которые журнал создаёт при открытии занятия
     * (`source = roster`), отметками не считаются: преподаватель их не ставил.
     */
    public function performance(Request $request, Group $group): array
    {
        $this->authorizeGroup($request, $group);

        $to = $this->date($request);
        $from = $request->query('date_from')
            ? CarbonImmutable::parse($request->query('date_from'))
            : $to->copy()->subDays(30);

        $students = $this->studentsOf($group);
        $studentIds = $students->pluck('id')->all();

        $lessons = JournalLesson::query()
            ->where('group_id', $group->id)
            ->whereDate('lesson_date', '>=', $from->toDateString())
            ->whereDate('lesson_date', '<=', $to->toDateString())
            ->pluck('id');

        $attendance = JournalAttendance::query()
            ->whereIn('journal_lesson_id', $lessons)
            ->whereIn('student_id', $studentIds)
            ->where('source', '!=', 'roster')
            ->get()
            ->groupBy('student_id');

        $grades = JournalGrade::query()
            ->whereIn('journal_lesson_id', $lessons)
            ->whereIn('student_id', $studentIds)
            ->whereNotNull('value')
            ->get()
            ->groupBy('student_id');

        $rows = $students->map(function (Student $student) use ($attendance, $grades): array {
            $marks = $attendance->get($student->id, collect());
            $values = $grades->get($student->id, collect())->pluck('value');
            $numeric = $values->filter(fn ($value): bool => is_numeric($value))->map(fn ($value): float => (float) $value);

            return [
                'student_id' => $student->id,
                'full_name' => $this->fullName($student),
                'marked' => $marks->count(),
                'absences' => $marks->where('status', 'absent')->count(),
                'lates' => $marks->where('status', 'late')->count(),
                'excused' => $marks->whereIn('status', ['excused', 'sick'])->count(),
                'grades' => $values->values()->all(),
                'average' => $numeric->isEmpty() ? null : round($numeric->avg(), 2),
            ];
        })->values();

        return ['data' => [
            'group' => ['id' => $group->id, 'name' => $group->name],
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'lessons' => $lessons->count(),
            'summary' => [
                'students' => $rows->count(),
                'absences' => $rows->sum('absences'),
                'lates' => $rows->sum('lates'),
                'without_grades' => $rows->where('average', null)->count(),
            ],
            'source' => 'Журнал: отметки и оценки преподавателя, а не проходная.',
            'rows' => $rows->all(),
        ]];
    }

    private function authorizeGroup(Request $request, Group $group): void
    {
        if (! $this->scope->curates($request->user(), $group)) {
            abort(403, 'Эта группа не закреплена за вами.');
        }
    }

    /** @return Collection<int, Student> */
    private function studentsOf(Group $group): Collection
    {
        return $this->roster->active($group);
    }

    /** @return array<string, mixed> */
    private function groupPayload(Group $group, ?int $studentsCount): array
    {
        return array_filter([
            'id' => $group->id,
            'name' => $group->name,
            'course' => $group->course,
            'specialty' => $group->specialty,
            'students_count' => $studentsCount,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function fullName(Student $student): string
    {
        return trim(implode(' ', array_filter([$student->last_name, $student->first_name, $student->middle_name])));
    }

    private function date(Request $request): Carbon
    {
        $value = $request->query('date');

        if ($value === null || $value === '') {
            return Carbon::today();
        }

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            abort(422, 'Дата должна быть передана в формате ГГГГ-ММ-ДД.');
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Exception) {
            abort(422, 'Дата некорректна.');
        }
    }
}
