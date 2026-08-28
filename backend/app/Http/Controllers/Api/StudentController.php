<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\Person;
use App\Models\Student;
use App\Services\Admissions\AdmissionDocumentReadinessService;
use App\Services\Admissions\IdentityDocumentService;
use App\Services\Admissions\SnilsService;
use App\Services\PersonService;
use App\Services\StudentCsvService;
use App\Services\StudentPersonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Support\Http\PageSize;

class StudentController extends Controller
{
    public function __construct(
        private readonly StudentCsvService $studentCsvService,
        private readonly SnilsService $snils,
        private readonly StudentPersonService $studentPeople,
        private readonly AdmissionDocumentReadinessService $readiness,
        private readonly IdentityDocumentService $identityDocuments,
        private readonly PersonService $people,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $students = Student::query()
            ->with('group.educationProgram.specialty')
            // Карты грузятся заранее: иначе на 596 студентах это 596 запросов.
            ->with('person.rfidCards')
            ->when($request->integer('group_id'), fn ($query, int $groupId) => $query->where('group_id', $groupId))
            // Курс и специальность — свойства группы, а не студента. Заводить
            // их копией в `students` нельзя: перевод группы на следующий курс
            // тогда пришлось бы разносить по всем её студентам вручную.
            ->when(
                $request->integer('course'),
                fn ($query, int $course) => $query->whereHas('group', fn ($group) => $group->onCourse($course)),
            )
            ->when(
                $request->integer('specialty_id'),
                fn ($query, int $specialtyId) => $query->whereHas(
                    'group.educationProgram',
                    fn ($program) => $program->where('specialty_id', $specialtyId),
                ),
            )
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            // Фильтр «неполные карточки» берёт идентификаторы у сервиса готовности:
            // признак в строке и список в фильтре обязаны считаться одинаково.
            ->when(
                $request->string('completeness')->toString() === 'incomplete',
                fn ($query) => $query->whereIn('id', $this->readiness->incompleteStudentIds()),
            )
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $operator = $query->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

                $query->where(function ($query) use ($operator, $search): void {
                    $query
                        ->where('last_name', $operator, "%{$search}%")
                        ->orWhere('first_name', $operator, "%{$search}%")
                        ->orWhere('middle_name', $operator, "%{$search}%")
                        // По номеру личного дела студента ищут не реже, чем по
                        // фамилии: в бумажных списках и ведомостях стоит он.
                        ->orWhere('personal_file_number', $operator, "%{$search}%");
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(PageSize::from($request, 20));

        $this->attachCompleteness($students->getCollection());

        return StudentResource::collection($students);
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $result = DB::transaction(function () use ($request): array {
            $data = $request->validated();

            if (filled($data['snils'] ?? null)) {
                $data['snils'] = $this->snils->normalize($data['snils']);
            }

            // Человек заводится всегда: паспорт и документ об образовании принадлежат
            // ему, а не карточке студента, и без Person их некуда прикрепить.
            $resolved = $this->studentPeople->resolveForData($data);
            $student = Student::create([...$data, 'person_id' => $resolved['person']->id]);
            $this->syncPassport($resolved['person']->id, $data, $request);
            $this->assertOrderAllowed($student, filled($data['enrollment_order_number'] ?? null));

            return [
                'student' => $student,
                'duplicate_candidates' => $resolved['duplicate_candidates'],
                'snils_missing' => blank($data['snils'] ?? null),
            ];
        });

        $student = $result['student'];
        $this->attachCompleteness(collect([$student]));

        return (new StudentResource($student->load('group.educationProgram.specialty')))
            ->additional(['warnings' => [
                'snils_missing' => $result['snils_missing'],
                'duplicate_candidates' => $result['duplicate_candidates'],
            ]])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Student $student): StudentResource
    {
        $this->attachCompleteness(collect([$student]));

        return new StudentResource($student->load('group.educationProgram.specialty'));
    }

    public function update(UpdateStudentRequest $request, Student $student): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $student): array {
            $data = $request->validated();

            if (array_key_exists('snils', $data) && filled($data['snils'])) {
                $data['snils'] = $this->snils->normalize($data['snils']);
            }

            $orderChanged = array_key_exists('enrollment_order_number', $data)
                && filled($data['enrollment_order_number'])
                && $data['enrollment_order_number'] !== $student->enrollment_order_number;

            $student->update($data);
            $student->refresh();

            $resolved = $this->studentPeople->ensureForStudent($student);
            $this->pushSharedDataToPerson($resolved['person'], $data);
            $student->refresh();
            $this->syncPassport($resolved['person']->id, $data, $request);
            $this->assertOrderAllowed($student, $orderChanged);

            return [
                'duplicate_candidates' => $resolved['duplicate_candidates'],
                'snils_missing' => blank($student->snils) && blank($resolved['person']->snils),
            ];
        });

        $this->attachCompleteness(collect([$student]));

        return (new StudentResource($student->load('group.educationProgram.specialty')))
            ->additional(['warnings' => [
                'snils_missing' => $result['snils_missing'],
                'duplicate_candidates' => $result['duplicate_candidates'],
            ]])
            ->response();
    }

    public function destroy(Student $student): Response
    {
        $student->delete();

        return response()->noContent();
    }

    public function export(): StreamedResponse
    {
        return $this->studentCsvService->export();
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        try {
            $summary = $this->studentCsvService->import($request->file('file'));
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => 'Импорт студентов завершен.',
            'data' => $summary,
        ]);
    }

    /**
     * ФИО и контакты студента — копия общих данных человека. Без записи в Person
     * исправление оставалось бы в карточке студента, а «Люди» показывали бы прежнее.
     *
     * СНИЛС отсюда намеренно не идёт: по нему человек находится и к нему привязываются
     * документы, поэтому подменять его правкой учебной карточки нельзя. Для этого есть
     * карточка человека.
     *
     * @param array<string, mixed> $data
     */
    private function pushSharedDataToPerson(Person $person, array $data): void
    {
        $this->people->updateFromProfile($person, Arr::only($data, ['last_name', 'first_name', 'middle_name', 'phone', 'email']));
    }

    /**
     * Паспорт с формы студента ложится в документ человека. Без этого оператор
     * заполнял бы поля паспорта, а карточка оставалась бы неполной.
     *
     * @param array<string, mixed> $data
     */
    private function syncPassport(int $personId, array $data, Request $request): void
    {
        if (! array_key_exists('passport_series', $data) && ! array_key_exists('passport_number', $data)) {
            return;
        }

        $this->identityDocuments->syncPassportForPerson($personId, [
            'series' => $data['passport_series'] ?? null,
            'number' => $data['passport_number'] ?? null,
            'issue_date' => $data['passport_issue_date'] ?? null,
            'issued_by' => $data['passport_issued_by'] ?? null,
            'subdivision_code' => $data['passport_department_code'] ?? null,
        ], $request->user());
    }

    /**
     * Приказ о зачислении — операция по закону, поэтому здесь блокировка жёсткая.
     * Проверяется только момент, когда номер приказа появляется или меняется: иначе
     * карточку с уже записанным приказом нельзя было бы редактировать вовсе.
     */
    private function assertOrderAllowed(Student $student, bool $orderChanged): void
    {
        if (! $orderChanged) {
            return;
        }

        $this->readiness->assertStudentCardComplete($student, 'Приказ о зачислении заблокирован', 'enrollment_order_number');
    }

    /**
     * Полнота карточки считается пакетно и кладётся на модель: реестр обязан показывать
     * неполноту без запроса на каждую строку.
     *
     * @param Collection<int, Student> $students
     */
    private function attachCompleteness(Collection $students): void
    {
        if ($students->isEmpty()) {
            return;
        }

        $cards = $this->readiness->forStudents($students);

        foreach ($students as $student) {
            $student->setAttribute('card_completeness', $cards[$student->id] ?? null);
        }
    }
}
