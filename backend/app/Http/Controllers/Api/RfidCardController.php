<?php

namespace App\Http\Controllers\Api;

use App\Support\Time\CollegeTime;
use App\Http\Controllers\Controller;
use App\Http\Resources\ImportJobResource;
use App\Http\Resources\RfidCardIssueResource;
use App\Http\Resources\RfidCardResource;
use App\Models\Group;
use App\Models\ImportJob;
use App\Models\Person;
use App\Models\RfidCard;
use App\Models\RfidCardIssue;
use App\Services\AuditLogService;
use App\Services\Import\RfidCardIssueImportHandler;
use App\Services\RfidCardJournalExport;
use App\Services\RfidCardService;
use App\Services\UniversalImportService;
use RuntimeException;
use App\Support\Rfid\CardNumber;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Учёт RFID-карт. Ведут комендант и отдел кадров.
 *
 * Экран заменяет тетрадь, и работа в нём идёт от двух вещей: от человека
 * (пришёл за картой) и от карты на считывателе (пришёл сдать). Поэтому кроме
 * списка карт здесь есть поиск человека, разбор номера со считывателя и журнал
 * выдач — он же печатная форма.
 *
 * Про людей отдаётся ровно то, что нужно для выдачи карты: фамилия, где человек
 * учится или работает, и какая карта у него на руках. Паспорт, телефон и адрес
 * коменданту для этого не нужны.
 */
class RfidCardController extends Controller
{
    /** Что подгружать, чтобы показать человека одной строкой. */
    private const PERSON_RELATIONS = [
        'primaryStudent.group',
        'primaryEmployee.primaryDepartment',
        'primaryTeacher',
    ];

    public function __construct(private readonly RfidCardService $cards)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(RfidCard::STATUSES)],
            'person_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $operator = $this->likeOperator();

        $cards = RfidCard::query()
            ->with('person')
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['person_id'] ?? null, fn ($query, int $id) => $query->where('person_id', $id))
            ->when($filters['search'] ?? null, function ($query, string $search) use ($operator): void {
                // Ищут и по номеру карты, и по фамилии владельца: комендант
                // одинаково часто держит в руках карту и слышит фамилию.
                $query->where(function ($query) use ($operator, $search): void {
                    $query->where('uid', $operator, "%{$search}%")
                        ->orWhere('label', $operator, "%{$search}%")
                        ->orWhereHas('person', fn ($person) => $person->where('last_name', $operator, "%{$search}%"));
                });
            })
            ->orderByRaw("case when status = 'issued' then 0 else 1 end")
            ->orderBy('uid')
            ->paginate($filters['per_page'] ?? 50);

        return RfidCardResource::collection($cards);
    }

    /**
     * Кто это по номеру карты.
     *
     * Сценарий «пришёл сдать карту»: комендант подносит её к считывателю, и
     * портал сам открывает нужного человека. Незнакомая карта — не ошибка, а
     * обычная ветка: значит, её сейчас заведут и выдадут.
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['uid' => ['required', 'string', 'max:100']]);
        $uid = CardNumber::normalize($data['uid']);

        $card = RfidCard::query()
            ->with(array_merge(['person'], array_map(fn (string $relation) => 'person.'.$relation, self::PERSON_RELATIONS)))
            ->firstWhere('uid', $uid);

        if ($card === null) {
            return response()->json(['found' => false, 'uid' => $uid, 'card' => null, 'person' => null]);
        }

        return response()->json([
            'found' => true,
            'uid' => $uid,
            'card' => (new RfidCardResource($card))->toArray($request),
            'person' => $card->person === null ? null : $this->personBrief($card->person),
        ]);
    }

    /**
     * Привязать карту к человеку по номеру со считывателя.
     *
     * Главный путь выдачи. Незнакомый номер заводится сам — отдельного шага
     * «сначала завести карту» не нужно.
     */
    public function bind(Request $request): RfidCardResource
    {
        $data = $request->validate([
            'person_id' => ['required', 'integer', 'exists:people,id'],
            'uid' => ['required', 'string', 'max:100'],
            'label' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $person = Person::query()->findOrFail($data['person_id']);
        $card = $this->cards->bind($person, $data['uid'], $data['label'] ?? null, $data['note'] ?? null);

        return new RfidCardResource($card->load('person'));
    }

    /**
     * Люди для выдачи карты.
     *
     * Отдельный узкий запрос, а не общий реестр: коменданту нужны фамилия,
     * группа или подразделение и то, есть ли карта на руках.
     */
    public function people(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'group_id' => ['nullable', 'integer'],
            'person_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $operator = $this->likeOperator();
        $search = trim((string) ($filters['search'] ?? ''));

        $people = Person::query()
            ->with(array_merge(self::PERSON_RELATIONS, ['currentRfidCard']))
            ->when($search !== '', function ($query) use ($operator, $search): void {
                foreach (preg_split('/\s+/', $search) ?: [] as $part) {
                    $query->where(function ($query) use ($operator, $part): void {
                        $query->where('last_name', $operator, "%{$part}%")
                            ->orWhere('first_name', $operator, "%{$part}%")
                            ->orWhere('middle_name', $operator, "%{$part}%");
                    });
                }
            })
            ->when($filters['group_id'] ?? null, fn ($query, int $id) => $query->whereHas(
                'students',
                fn ($students) => $students->where('group_id', $id),
            ))
            // Точечное обновление: после выдачи или приёма экран перечитывает
            // того же человека, а не ищет его заново по фамилии.
            ->when($filters['person_id'] ?? null, fn ($query, int $id) => $query->whereKey($id))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit($filters['limit'] ?? 25)
            ->get();

        return response()->json(['data' => $people->map(fn (Person $person) => $this->personBrief($person))->all()]);
    }

    /**
     * Группы для ведомости.
     *
     * Отдельным узким списком, а не через раздел групп: коменданту для выдачи
     * карт нужны только название и курс, а право на раздел групп открыло бы
     * учебные планы и кураторов.
     */
    public function groups(): JsonResponse
    {
        // Курс считается из года набора, поэтому и порядок по нему: свежий
        // набор — это первый курс.
        // Специальность отдаётся не для красоты: выпадающий список группируется по
        // ней заголовками — решение владельца от 23.08.2026. Без этого поля
        // `buildGroupOptions` считает специальность пустой у **всех** групп, все 58
        // ложатся под один заголовок «Без специальности», и группировки не
        // происходит вовсе — молча, потому что список при этом рисуется и работает.
        // Замечено 24.08.2026: на экране карт она так и не исполнялась ни дня.
        $groups = Group::query()
            ->orderByDesc('year_start')
            ->orderBy('name')
            ->get(['id', 'name', 'year_start', 'specialty'])
            ->map(fn (Group $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'course' => $group->course,
                'specialty' => $group->specialty,
            ]);

        return response()->json(['data' => $groups]);
    }

    /**
     * Журнал выдач за период — он же печатная форма.
     *
     * Отдельно от списка карт: список отвечает «где карта сейчас», журнал —
     * «что происходило». Печатают именно журнал.
     */
    public function journal(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'person_id' => ['nullable', 'integer'],
            'rfid_card_id' => ['nullable', 'integer'],
            'group_id' => ['nullable', 'integer'],
            'reason' => ['nullable', Rule::in(RfidCardIssue::REASONS)],
            'open' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ], [
            // Без своих сообщений наружу уходит служебный ключ вида
            // `validation.boolean`, и человек видит его вместо объяснения.
            'from.date' => 'Дата «с» не распознана.',
            'to.date' => 'Дата «по» не распознана.',
            'open.boolean' => 'Состояние выдачи задаётся как «на руках» или «закрыта».',
            'reason.in' => 'Неизвестная причина закрытия выдачи.',
            'per_page.integer' => 'Число строк на странице должно быть целым.',
        ]);

        $issues = $this->journalQuery($filters)->paginate($filters['per_page'] ?? 100);

        return RfidCardIssueResource::collection($issues);
    }

    /**
     * Тот же журнал книгой Excel.
     *
     * Отбор строк общий с экраном — намеренно: выгрузка, которая показывает не
     * то же самое, что человек видит перед собой, хуже, чем её отсутствие.
     */
    public function exportJournal(Request $request, RfidCardJournalExport $export): Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'person_id' => ['nullable', 'integer'],
            'rfid_card_id' => ['nullable', 'integer'],
            'group_id' => ['nullable', 'integer'],
            'reason' => ['nullable', Rule::in(RfidCardIssue::REASONS)],
            'open' => ['nullable', 'boolean'],
        ]);

        $issues = $this->journalQuery($filters)->limit(10000)->get();
        $file = $export->build($issues, $this->journalTitle($filters), $this->journalPeriod($filters));

        AuditLogService::log('rfid', 'journal_exported', null, null, ['rows' => $issues->count()], $request);

        return response($file['content'], Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }

    /**
     * Загрузка журнала из файла — рядом с выгрузкой, без похода в другой раздел.
     *
     * Механизм тот же, что у общего импорта: сопоставление колонок, проверка
     * без записи, только потом запись. Второй реализации нет намеренно — иначе
     * два пути разошлись бы поведением, и человек не знал бы, какому верить.
     * Отдельный маршрут нужен из-за прав: у коменданта есть `rfid.cards.manage`
     * и нет `import.manage`, открывающего загрузку всего подряд.
     */
    public function importJournalPreview(Request $request, UniversalImportService $import): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
        ], [
            'file.required' => 'Выберите файл журнала.',
            'file.mimes' => 'Журнал загружается книгой Excel или файлом CSV.',
        ]);

        try {
            $job = $import->createPreview($request->file('file'), RfidCardIssueImportHandler::TYPE, $request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => new ImportJobResource($job->fresh()->load('user'))], Response::HTTP_CREATED);
    }

    public function importJournalConfirm(Request $request, ImportJob $importJob, UniversalImportService $import): JsonResponse
    {
        abort_unless($importJob->data_type === RfidCardIssueImportHandler::TYPE, Response::HTTP_NOT_FOUND);

        $data = $request->validate([
            'mode' => ['nullable', 'string'],
        ]);

        try {
            $job = $import->confirmJob($importJob, $importJob->mapping ?? [], $data['mode'] ?? 'skip_duplicates');
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        AuditLogService::log('rfid', 'journal_imported', $job, null, ['summary' => $job->summary], $request);

        return response()->json(['data' => new ImportJobResource($job->fresh()->load('user'))]);
    }

    private function journalQuery(array $filters): Builder
    {
        return RfidCardIssue::query()
            ->with(array_merge(
                ['card', 'issuedBy', 'acceptedBy', 'person'],
                array_map(fn (string $relation) => 'person.'.$relation, self::PERSON_RELATIONS),
            ))
            // Границы суток колледжа, а не UTC. Пока склеивали строкой, отбор
            // «за 22-е» давал ноль на карте, выданной 22-го в 00:17, и ведомость
            // за день выдачи печаталась пустой при верных данных.
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('issued_at', '>=', CollegeTime::dayStart($from)))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('issued_at', '<=', CollegeTime::dayEnd($to)))
            ->when($filters['person_id'] ?? null, fn ($query, int $id) => $query->where('person_id', $id))
            ->when($filters['rfid_card_id'] ?? null, fn ($query, int $id) => $query->where('rfid_card_id', $id))
            ->when($filters['reason'] ?? null, fn ($query, string $reason) => $query->where('close_reason', $reason))
            ->when($filters['group_id'] ?? null, fn ($query, int $id) => $query->whereHas(
                'person.students',
                fn ($students) => $students->where('group_id', $id),
            ))
            ->when(array_key_exists('open', $filters) && $filters['open'] !== null, function ($query) use ($filters): void {
                $filters['open']
                    ? $query->whereNull('returned_at')
                    : $query->whereNotNull('returned_at');
            })
            ->orderByDesc('issued_at')
            ->orderByDesc('id');
    }

    private function journalTitle(array $filters): string
    {
        $groupId = $filters['group_id'] ?? null;

        if ($groupId === null) {
            return 'Журнал выдачи RFID-карт';
        }

        $group = Group::query()->find($groupId);

        return $group === null
            ? 'Ведомость выдачи RFID-карт'
            : 'Ведомость выдачи RFID-карт — '.$group->name;
    }

    private function journalPeriod(array $filters): string
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        if ($from === null && $to === null) {
            return 'за всё время';
        }

        $format = fn (?string $value): string => $value === null ? '…' : Carbon::parse($value)->format('d.m.Y');

        return 'за период '.$format($from).' — '.$format($to);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uid' => ['required', 'string', 'max:100'],
            'label' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $uid = CardNumber::normalize($data['uid']);

        if (RfidCard::query()->where('uid', $uid)->exists()) {
            throw ValidationException::withMessages(['uid' => 'Карта с таким номером уже заведена.']);
        }

        $card = RfidCard::create([
            'uid' => $uid,
            'uid_raw' => trim($data['uid']),
            'label' => $data['label'] ?? null,
            'note' => $data['note'] ?? null,
            'status' => RfidCard::STATUS_STOCK,
        ]);

        AuditLogService::log('rfid', 'card_created', $card, null, $card->only(['uid', 'label', 'status']), $request);

        return (new RfidCardResource($card))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(Request $request, RfidCard $rfidCard): RfidCardResource
    {
        $data = $request->validate([
            'uid' => ['sometimes', 'required', 'string', 'max:100'],
            'label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        if (array_key_exists('uid', $data)) {
            $data['uid_raw'] = trim($data['uid']);
            $data['uid'] = CardNumber::normalize($data['uid']);

            $taken = RfidCard::query()->where('uid', $data['uid'])->whereKeyNot($rfidCard->id)->exists();

            if ($taken) {
                throw ValidationException::withMessages(['uid' => 'Карта с таким номером уже заведена.']);
            }
        }

        $old = $rfidCard->only(['uid', 'label', 'note']);
        $rfidCard->update($data);

        AuditLogService::log('rfid', 'card_updated', $rfidCard, $old, $rfidCard->only(['uid', 'label', 'note']), $request);

        return new RfidCardResource($rfidCard->load('person'));
    }

    public function issue(Request $request, RfidCard $rfidCard): RfidCardResource
    {
        $data = $request->validate([
            'person_id' => ['required', 'integer', 'exists:people,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $person = Person::query()->findOrFail($data['person_id']);

        return new RfidCardResource($this->cards->issue($rfidCard, $person, $data['note'] ?? null));
    }

    public function accept(Request $request, RfidCard $rfidCard): RfidCardResource
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return new RfidCardResource($this->cards->accept($rfidCard, $data['note'] ?? null));
    }

    /**
     * Отвязать карту от человека, не принимая её физически.
     *
     * Человек уволился или отчислился, карта осталась у него или пропала — она
     * перестаёт за ним числиться, и её можно выдать другому.
     */
    public function release(Request $request, RfidCard $rfidCard): RfidCardResource
    {
        $data = $request->validate([
            'reason' => ['nullable', Rule::in(RfidCardIssue::REASONS)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return new RfidCardResource($this->cards->release(
            $rfidCard,
            $data['reason'] ?? RfidCardIssue::REASON_RETURNED,
            $data['note'] ?? null,
        ));
    }

    /** Удалить карту, которой никогда никого не выдавали. */
    public function destroy(RfidCard $rfidCard): JsonResponse
    {
        $this->cards->delete($rfidCard);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function status(Request $request, RfidCard $rfidCard): RfidCardResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(RfidCard::STATUSES)],
            'reason' => ['nullable', Rule::in(RfidCardIssue::REASONS)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return new RfidCardResource($this->cards->changeStatus(
            $rfidCard,
            $data['status'],
            $data['note'] ?? null,
            $data['reason'] ?? null,
        ));
    }

    /** Человек одной строкой: столько, сколько нужно для выдачи карты. */
    private function personBrief(Person $person): array
    {
        $student = $person->primaryStudent;
        $employee = $person->primaryEmployee;
        $teacher = $person->primaryTeacher;
        $card = $person->currentRfidCard;

        return [
            'id' => $person->id,
            'full_name' => trim(implode(' ', array_filter([
                $person->last_name,
                $person->first_name,
                $person->middle_name,
            ]))),
            'kind' => match (true) {
                $student !== null => 'Студент',
                $teacher !== null => 'Преподаватель',
                $employee !== null => 'Сотрудник',
                default => 'Без карточки',
            },
            'unit' => $student?->group?->name ?? $employee?->primaryDepartment?->name,
            'card' => $card === null ? null : [
                'id' => $card->id,
                'uid' => $card->uid,
                'label' => $card->label,
                'status' => $card->status,
                'status_label' => RfidCardResource::statusLabel($card->status),
                'issued_at' => $card->issued_at?->toISOString(),
            ],
        ];
    }

    private function likeOperator(): string
    {
        return RfidCard::query()->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }
}
