<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTeacherRequest;
use App\Http\Requests\UpdateTeacherRequest;
use App\Http\Resources\TeacherResource;
use App\Models\Person;
use App\Models\Teacher;
use App\Services\PersonService;
use App\Services\TeacherCsvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeacherController extends Controller
{
    public function __construct(
        private readonly TeacherCsvService $teacherCsvService,
        private readonly PersonService $people,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        // Список отдавал ровно двадцать строк и `per_page` не спрашивал вовсе.
        // Пока преподавателей было четверо, это не значило ничего; 28.08.2026 их
        // стало 177, и первыми же двадцатью по алфавиту обрывались все справочники
        // разом: выбор преподавателя в расписании, в нагрузке, у дисциплины и у
        // группы. Кабинет самого преподавателя ломался тем же: TeacherDashboard
        // ищет себя в присланных строках и за двадцатой себя не находил.
        //
        // Потолок 500 взят с запасом к 177 замеренным: он ограничивает ответ, а не
        // список — двадцать по умолчанию остаётся тем, кто ничего не просил.
        $perPage = min(max((int) ($request->integer('per_page') ?: 20), 1), 500);

        $teachers = Teacher::query()
            ->when($request->string('department')->toString(), fn ($query, string $department) => $query->where('department', $department))
            ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $search = mb_strtolower($search);

                $query->where(function ($query) use ($search): void {
                    $query
                        ->whereRaw('lower(last_name) like ?', ["%{$search}%"])
                        ->orWhereRaw('lower(first_name) like ?', ["%{$search}%"])
                        ->orWhereRaw('lower(middle_name) like ?', ["%{$search}%"]);
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($perPage);

        return TeacherResource::collection($teachers);
    }

    public function store(StoreTeacherRequest $request): JsonResponse
    {
        $data = $request->validated();

        $teacher = DB::transaction(function () use ($data): Teacher {
            $teacher = Teacher::create($data);
            $person = $this->resolvePerson($teacher, $data['person_id'] ?? null);

            $this->people->linkProfile($teacher, $person);
            // Тем же путём, что и правка: ФИО и контакты принадлежат человеку, а
            // копия в карточке — зеркало. Заодно копия и человек не расходятся
            // с первой же секунды, если карточка легла на уже заведённого.
            $this->people->updateFromProfile($person, $data);

            return $teacher->refresh();
        });

        return (new TeacherResource($teacher))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Человек для новой карточки преподавателя.
     *
     * Заводится всегда — как при зачислении студента и при заведении сотрудника.
     * Без него карточка остаётся сиротой: в «Людях» её не видно, кадровая запись
     * и цифровая идентичность к ней не пристыковываются, а зеркало общих данных
     * до неё не достаёт, потому что ищет по `person_id`. Ровно это и случилось с
     * карточками, заведёнными через API на боевом портале 16.08.2026.
     */
    private function resolvePerson(Teacher $teacher, ?int $personId): Person
    {
        if ($personId !== null) {
            return Person::findOrFail($personId);
        }

        $data = $this->people->dataFromProfile($teacher);
        $duplicates = $this->people->findPossibleDuplicates($data);

        if ($duplicates->count() > 1) {
            throw ValidationException::withMessages([
                'person_id' => ['Нашлось несколько подходящих карточек человека. Укажите нужную явно и повторите.'],
            ]);
        }

        return $duplicates->first() ?: $this->people->createPerson($data);
    }

    public function show(Teacher $teacher): TeacherResource
    {
        return new TeacherResource($teacher);
    }

    public function update(UpdateTeacherRequest $request, Teacher $teacher): TeacherResource
    {
        $data = $request->validated();
        $teacher->update($data);

        // ФИО и контакты преподавателя — копия общих данных человека. Без записи в Person
        // исправление оставалось только здесь, а «Люди» и «Сотрудники» продолжали показывать
        // прежнее и при следующем сохранении кадровой карточки возвращали его обратно.
        if ($teacher->person) {
            $this->people->updateFromProfile($teacher->person, $data);
            $teacher->refresh();
        }

        return new TeacherResource($teacher);
    }

    public function destroy(Teacher $teacher): Response
    {
        $teacher->delete();

        return response()->noContent();
    }

    public function export(): StreamedResponse
    {
        return $this->teacherCsvService->export();
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $summary = $this->teacherCsvService->import($request->file('file'));

        return response()->json(['data' => $summary]);
    }
}
