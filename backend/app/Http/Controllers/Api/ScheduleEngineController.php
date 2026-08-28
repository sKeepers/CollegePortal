<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduleEntryResource;
use App\Models\ScheduleEntry;
use App\Models\ScheduleTemplate;
use App\Services\ScheduleEngineService;
use App\Support\Csv\CsvExport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScheduleEngineController extends Controller
{
    public function __construct(private readonly ScheduleEngineService $scheduleEngineService)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return ScheduleEntryResource::collection(
            $this->scheduleEngineService->query($request->query())->paginate((int) $request->query('per_page', 50))
        );
    }

    public function preview(Request $request): JsonResponse
    {
        return response()->json($this->scheduleEngineService->preview($this->validatedEntry($request)));
    }

    public function validateEntry(Request $request): JsonResponse
    {
        return response()->json($this->scheduleEngineService->preview($this->validatedEntry($request)));
    }

    public function apply(Request $request): JsonResponse
    {
        $result = $this->scheduleEngineService->apply($this->validatedEntry($request), $request->user());
        $entry = $result['entry'];
        $result['entry'] = new ScheduleEntryResource($entry);

        return response()->json($result, Response::HTTP_CREATED);
    }

    public function conflicts(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->scheduleEngineService->conflicts($request->query())]);
    }

    public function coverage(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->scheduleEngineService->coverage($request->query())]);
    }

    public function group(int $groupId, Request $request): AnonymousResourceCollection
    {
        return ScheduleEntryResource::collection($this->scheduleEngineService->query([...$request->query(), 'group_id' => $groupId])->get());
    }

    public function teacher(int $teacherId, Request $request): AnonymousResourceCollection
    {
        return ScheduleEntryResource::collection($this->scheduleEngineService->query([...$request->query(), 'teacher_id' => $teacherId])->get());
    }

    public function classroom(int $classroomId, Request $request): AnonymousResourceCollection
    {
        return ScheduleEntryResource::collection($this->scheduleEngineService->query([...$request->query(), 'classroom_id' => $classroomId])->get());
    }

    /**
     * Расписание на стену: неделя одной группы или одного преподавателя.
     *
     * Дни по столбцам, пары по строкам — так расписание вывешивают и раздают по
     * кабинетам. Списком его читать нельзя, а учебная часть иначе будет сводить
     * ту же таблицу в Excel руками, имея её готовой в портале.
     */
    private const WEEKDAYS = [1 => 'понедельник', 2 => 'вторник', 3 => 'среда', 4 => 'четверг', 5 => 'пятница', 6 => 'суббота', 7 => 'воскресенье'];

    public function weekReport(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->buildWeek($request)]);
    }

    public function exportWeek(Request $request): StreamedResponse
    {
        $week = $this->buildWeek($request);

        $headers = array_merge(['Пара'], array_column($week['days'], 'column'));

        return CsvExport::download('schedule-week.csv', $headers, function (callable $row) use ($week): void {
            foreach ($week['rows'] as $slot) {
                $cells = [];
                foreach ($week['days'] as $day) {
                    $cell = $slot['cells'][$day['date']] ?? null;
                    $cells[] = $cell ? implode(', ', array_filter($cell['lines'])) : '';
                }
                $row(array_merge([$slot['title']], $cells));
            }
        });
    }

    /** @return array<string, mixed> */
    private function buildWeek(Request $request): array
    {
        $data = $request->validate([
            'group_id' => ['required_without:teacher_id', 'nullable', 'integer', 'exists:groups,id'],
            'teacher_id' => ['required_without:group_id', 'nullable', 'integer', 'exists:teachers,id'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $forGroup = ! empty($data['group_id']);

        $entries = $this->scheduleEngineService->query([
            'group_id' => $data['group_id'] ?? null,
            'teacher_id' => $data['teacher_id'] ?? null,
            'date_from' => $data['date_from'],
            'date_to' => $data['date_to'],
        ])->get()->filter(fn (ScheduleEntry $entry): bool => $entry->status !== 'canceled' && $entry->date !== null);

        // Столбцы строятся по запрошенному промежутку, а не по дням, где что-то
        // есть. День без занятий обязан остаться пустым столбцом: на стене неделя
        // должна быть целой, иначе читающий решит, что лист напечатан не весь.
        // Воскресенье показывается, только если в нём есть занятие.
        $from = CarbonImmutable::parse($data['date_from'])->startOfDay();
        $to = CarbonImmutable::parse($data['date_to'])->startOfDay();

        abort_if($from->diffInDays($to) > 31, 422, 'Форма рассчитана на неделю или месяц, но не больше 31 дня.');

        $busy = [];
        foreach ($entries as $entry) {
            $busy[$entry->date->toDateString()] = true;
        }

        $days = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $date = $day->toDateString();

            if ($day->dayOfWeekIso === 7 && ! isset($busy[$date])) {
                continue;
            }

            $days[$date] = [
                'date' => $date,
                'weekday' => self::WEEKDAYS[$day->dayOfWeekIso] ?? '',
                'column' => self::WEEKDAYS[$day->dayOfWeekIso].', '.$day->format('d.m'),
            ];
        }

        // Пары нумерует расписание; если номера нет, строку задаёт время начала —
        // иначе занятие просто исчезнет из таблицы.
        $slots = [];
        foreach ($entries as $entry) {
            $startsAt = $this->timeOf($entry->starts_at);
            $endsAt = $this->timeOf($entry->ends_at);
            $key = $entry->lesson_number ?: (int) str_replace(':', '', $startsAt);
            $time = trim($startsAt.'–'.$endsAt, '–');
            $slots[$key] ??= [
                'key' => $key,
                'lesson_number' => $entry->lesson_number,
                'title' => $entry->lesson_number ? $entry->lesson_number.' пара, '.$time : $time,
                'cells' => [],
            ];

            $lines = $forGroup
                ? [$entry->subject?->name, $this->shortName($entry->teacher), $entry->classroom?->number ? 'ауд. '.$entry->classroom->number : null]
                : [$entry->subject?->name, $entry->group?->name, $entry->classroom?->number ? 'ауд. '.$entry->classroom->number : null];

            $date = $entry->date->toDateString();

            // Две записи в одну клетку — это не норма, но случается при подгруппах
            // и при замене: показать надо обе, а не потерять одну молча.
            if (isset($slots[$key]['cells'][$date])) {
                $slots[$key]['cells'][$date]['lines'][] = '+ '.implode(', ', array_filter($lines));
                continue;
            }

            $slots[$key]['cells'][$date] = [
                'entry_id' => $entry->id,
                'is_replacement' => (bool) $entry->is_replacement,
                'lines' => array_values(array_filter($lines)),
            ];
        }
        ksort($slots);

        return [
            'for' => $forGroup ? 'group' : 'teacher',
            'title' => $forGroup
                ? ($entries->first()?->group?->name ?? 'Группа')
                : ($this->shortName($entries->first()?->teacher) ?: 'Преподаватель'),
            'date_from' => $data['date_from'],
            'date_to' => $data['date_to'],
            'days' => array_values($days),
            'rows' => array_values($slots),
        ];
    }

    /**
     * Время занятия приходит объектом даты, и «обрезать первые пять знаков» даёт
     * «2026-». Час с минутами надо брать форматированием.
     */
    private function timeOf(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }

        return substr((string) $value, 0, 5);
    }

    private function shortName(?object $teacher): string
    {
        if ($teacher === null) {
            return '';
        }

        $initials = trim(
            ($teacher->first_name ? mb_substr($teacher->first_name, 0, 1).'.' : '')
            .($teacher->middle_name ? mb_substr($teacher->middle_name, 0, 1).'.' : '')
        );

        return trim($teacher->last_name.' '.$initials);
    }

    public function replaceTeacher(ScheduleEntry $scheduleEntry, Request $request): ScheduleEntryResource
    {
        $data = $request->validate(['teacher_id' => ['required', 'integer', 'exists:teachers,id']]);
        return new ScheduleEntryResource($this->scheduleEngineService->replaceTeacher($scheduleEntry, (int) $data['teacher_id'], $request->user()));
    }

    public function replaceClassroom(ScheduleEntry $scheduleEntry, Request $request): ScheduleEntryResource
    {
        $data = $request->validate(['classroom_id' => ['nullable', 'integer', 'exists:classrooms,id']]);
        return new ScheduleEntryResource($this->scheduleEngineService->replaceClassroom($scheduleEntry, isset($data['classroom_id']) ? (int) $data['classroom_id'] : null, $request->user()));
    }

    public function move(ScheduleEntry $scheduleEntry, Request $request): ScheduleEntryResource
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'lesson_number' => ['nullable', 'integer', 'min:0', 'max:15'],
        ]);
        return new ScheduleEntryResource($this->scheduleEngineService->move($scheduleEntry, $data, $request->user()));
    }

    public function cancel(ScheduleEntry $scheduleEntry, Request $request): ScheduleEntryResource
    {
        return new ScheduleEntryResource($this->scheduleEngineService->cancel($scheduleEntry, $request->user()));
    }

    public function restore(ScheduleEntry $scheduleEntry, Request $request): ScheduleEntryResource
    {
        return new ScheduleEntryResource($this->scheduleEngineService->restore($scheduleEntry, $request->user()));
    }


    public function templates(Request $request): JsonResponse
    {
        $templates = ScheduleTemplate::query()
            ->with(['group', 'entries.subject', 'entries.teacher', 'entries.classroom'])
            ->when($request->integer('group_id'), fn ($query, int $groupId) => $query->where('group_id', $groupId))
            ->when($request->query('academic_year'), fn ($query, string $year) => $query->where('academic_year', $year))
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $templates]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'academic_year' => ['required', 'string', 'max:20'],
            'semester' => ['required', 'integer', 'min:1', 'max:12'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'week_type' => ['nullable', Rule::in(['all', 'even', 'odd'])],
            'status' => ['nullable', Rule::in(['draft', 'active', 'archived'])],
            'entries' => ['array'],
            'entries.*.day_of_week' => ['required', 'integer', 'min:1', 'max:7'],
            'entries.*.week_type' => ['nullable', Rule::in(['all', 'even', 'odd'])],
            'entries.*.lesson_number' => ['required', 'integer', 'min:0', 'max:15'],
            'entries.*.starts_at' => ['required', 'date_format:H:i'],
            'entries.*.ends_at' => ['required', 'date_format:H:i'],
            'entries.*.subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'entries.*.teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'entries.*.classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
            'entries.*.teaching_load_item_id' => ['nullable', 'integer', 'exists:teaching_load_items,id'],
            'entries.*.lesson_type_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'entries.*.comment' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->scheduleEngineService->createTemplate($data, $request->user())], Response::HTTP_CREATED);
    }

    public function templateApplyPreview(ScheduleTemplate $scheduleTemplate, Request $request): JsonResponse
    {
        $data = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        return response()->json($this->scheduleEngineService->applyTemplatePreview($scheduleTemplate, $data['date_from'], $data['date_to']));
    }

    public function templateApply(ScheduleTemplate $scheduleTemplate, Request $request): JsonResponse
    {
        $data = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        return response()->json($this->scheduleEngineService->applyTemplateConfirm($scheduleTemplate, $data['date_from'], $data['date_to'], $request->user()));
    }

    private function validatedEntry(Request $request): array
    {
        return $request->validate([
            'academic_year' => ['nullable', 'string', 'max:20'],
            'semester' => ['nullable', 'integer', 'min:1', 'max:12'],
            'date' => ['nullable', 'date'],
            'lesson_date' => ['nullable', 'date'],
            'day_of_week' => ['nullable', 'integer', 'min:1', 'max:7'],
            'week_type' => ['nullable', Rule::in(['even', 'odd', 'all'])],
            'lesson_number' => ['nullable', 'integer', 'min:0', 'max:15'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
            'teaching_load_item_id' => ['nullable', 'integer', 'exists:teaching_load_items,id'],
            'lesson_type_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            'status' => ['nullable', Rule::in(['draft', 'scheduled', 'canceled', 'moved'])],
            'source' => ['nullable', 'string', 'max:50'],
            'is_replacement' => ['nullable', 'boolean'],
            'replaced_entry_id' => ['nullable', 'integer', 'exists:schedule_entries,id'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'topic' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
