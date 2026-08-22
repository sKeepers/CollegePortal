<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGroupRequest;
use App\Http\Requests\UpdateGroupRequest;
use App\Http\Resources\GroupResource;
use App\Models\Group;
use App\Services\GroupCsvService;
use App\Services\AutoCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GroupController extends Controller
{
    public function __construct(
        private readonly GroupCsvService $groupCsvService,
        private readonly AutoCodeService $autoCodeService,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        // Список групп питает выпадающие списки на чужих экранах — фильтры
        // студентов берут из него и курс, и специальность. На странице в
        // двадцать строк половина групп в них не попадала, и фильтр молча
        // предлагал неполный выбор.
        $perPage = min(max((int) ($request->integer('per_page') ?: 20), 1), 200);

        $groups = Group::query()
            ->with('curator')
            ->with('educationProgram.specialty')
            ->withCount('students')
            ->orderBy('name')
            ->paginate($perPage);

        return GroupResource::collection($groups);
    }

    public function store(StoreGroupRequest $request): JsonResponse
    {
        $data = $request->validated();
        $yearStart = (int) ($data['year_start'] ?? now()->year);
        $data['name'] = $data['name'] ?: $this->autoCodeService->groupName($data['specialty'] ?? null, $yearStart, Group::academicYear() - $yearStart + 1);
        $group = Group::create($data);

        return (new GroupResource($group->load(['curator', 'educationProgram.specialty'])->loadCount('students')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Group $group): GroupResource
    {
        return new GroupResource($group->load(['curator', 'educationProgram.specialty'])->loadCount('students'));
    }

    public function update(UpdateGroupRequest $request, Group $group): GroupResource
    {
        $data = $request->validated();
        if (array_key_exists('name', $data) && ! $data['name']) {
            $yearStart = (int) ($data['year_start'] ?? $group->year_start);
            $data['name'] = $this->autoCodeService->groupName($data['specialty'] ?? $group->specialty, $yearStart, Group::academicYear() - $yearStart + 1);
        }
        $group->update($data);

        return new GroupResource($group->load(['curator', 'educationProgram.specialty'])->loadCount('students'));
    }

    /**
     * Связи, из-за которых группу удалять нельзя, и как назвать их человеку.
     *
     * Список снят с самой базы, а не из моделей: у `groups` девять внешних
     * ключей, и семь из них — `ON DELETE CASCADE`. Каскад срабатывает **в
     * PostgreSQL, мимо Eloquent**: мягкое удаление `students.deleted_at` не
     * участвует, в корзину не попадает ничего, восстанавливать нечем.
     *
     * `graduates` и `teaching_loads` стоят на `SET NULL` — они переживут
     * удаление, но потеряют связь с группой, и по какой группе человек
     * выпускался, будет уже не сказать. Поэтому мешают тоже.
     *
     * @var array<string, string>
     */
    private const DELETION_BLOCKERS = [
        'students' => 'студентов',
        'journal_lessons' => 'записей журнала',
        'schedule_lessons' => 'занятий расписания',
        'schedule_entries' => 'строк расписания',
        'schedule_templates' => 'шаблонов расписания',
        'exams' => 'экзаменов',
        'teaching_load_items' => 'строк нагрузки',
        'graduates' => 'выпускников',
        'teaching_loads' => 'нагрузок',
    ];

    /**
     * Удаление группы.
     *
     * Пустую группу удалять можно и нужно — это рабочий случай. Непустую
     * портал **не удаляет и называет, что именно мешает**: до 23.08.2026 здесь
     * стоял простой `$group->delete()`, и один вопрос «Удалить группу?» уносил
     * всех её студентов вместе с журналом, расписанием и экзаменами.
     */
    public function destroy(Group $group): Response|JsonResponse
    {
        $blockers = $this->deletionBlockers($group);

        if ($blockers !== []) {
            return response()->json([
                'message' => 'Группу нельзя удалить: с ней связаны '.implode(', ', array_map(
                    static fn (array $item): string => $item['count'].' '.$item['label'],
                    $blockers,
                )).'. Удаление уносит эти записи с собой, и восстановить их будет нечем. Переведите студентов в другую группу и снимите с группы расписание.',
                'blockers' => $blockers,
            ], Response::HTTP_CONFLICT);
        }

        $group->delete();

        return response()->noContent();
    }

    /**
     * Что мешает удалить группу. Считается всё сразу, а не до первой находки:
     * человеку нужен весь список, иначе он будет узнавать о препятствиях по одному.
     *
     * @return list<array{table:string,label:string,count:int}>
     */
    private function deletionBlockers(Group $group): array
    {
        $blockers = [];

        foreach (self::DELETION_BLOCKERS as $table => $label) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'group_id')) {
                continue;
            }

            $count = DB::table($table)->where('group_id', $group->getKey())->count();

            if ($count > 0) {
                $blockers[] = ['table' => $table, 'label' => $label, 'count' => $count];
            }
        }

        return $blockers;
    }

    public function export(): StreamedResponse
    {
        return $this->groupCsvService->export();
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $summary = $this->groupCsvService->import($request->file('file'));

        return response()->json(['data' => $summary]);
    }
}
