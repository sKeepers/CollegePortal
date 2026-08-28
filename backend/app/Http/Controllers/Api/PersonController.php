<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DuplicatePersonCheckRequest;
use App\Http\Requests\MergePeopleRequest;
use App\Http\Requests\StorePersonRequest;
use App\Http\Requests\UpdatePersonRequest;
use App\Http\Resources\PersonResource;
use App\Models\Person;
use App\Services\AuditLogService;
use App\Services\People\PersonMergeService;
use App\Services\PersonDuplicateService;
use App\Services\PersonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use App\Support\Http\PageSize;

class PersonController extends Controller
{
    public function __construct(private readonly PersonService $people)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $operator = Person::query()->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $search = $request->string('search')->toString();
        $profile = $request->string('profile')->toString();

        $people = Person::query()
            ->withCount(['students', 'teachers', 'employees', 'applicants', 'applicantApplications', 'graduates', 'users', 'digitalIdentities'])
            ->when($search, function ($query) use ($operator, $search): void {
                $query->where(function ($query) use ($operator, $search): void {
                    $query->where('last_name', $operator, "%{$search}%")
                        ->orWhere('first_name', $operator, "%{$search}%")
                        ->orWhere('middle_name', $operator, "%{$search}%")
                        ->orWhere('email', $operator, "%{$search}%")
                        ->orWhere('phone', $operator, "%{$search}%");
                });
            })
            ->when($profile, function ($query) use ($profile): void {
                // Person is the single registry of every human in the system, so the list must
                // not hide anyone by itself: someone who is both a student and an employee has
                // to stay findable. Excluding students is a filter the caller asks for, not a
                // silent rule of the endpoint.
                match ($profile) {
                    'student' => $query->has('students'),
                    'without_students' => $query->whereDoesntHave('students'),
                    'teacher' => $query->has('teachers'),
                    'employee' => $query->has('employees'),
                    'applicant' => $query->where(fn ($profileQuery) => $profileQuery->has('applicants')->orHas('applicantApplications')),
                    'graduate' => $query->has('graduates'),
                    'user' => $query->has('users'),
                    default => null,
                };
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(PageSize::from($request, 30));

        return PersonResource::collection($people);
    }

    public function show(Person $person): PersonResource
    {
        return new PersonResource($person->load([
            'students.group',
            'teachers.subjects',
            'employees.primaryDepartment',
            'employees.primaryPosition',
            'applicants.status',
            'applicants.source',
            'applicantApplications.educationProgram',
            'graduates.student',
            'graduates.group',
            'graduates.diploma',
            'users.roles',
            'digitalIdentities',
            'currentRfidCard',
        ])->loadCount(['students', 'teachers', 'employees', 'applicants', 'applicantApplications', 'graduates', 'users', 'digitalIdentities']));
    }

    public function store(StorePersonRequest $request): JsonResponse
    {
        $person = $this->people->createPerson($request->validated());

        AuditLogService::log('Identity', 'person_created', $person, null, [
            'id' => $person->id,
            'has_snils_hash' => filled($person->snils_hash),
            'status' => $person->status,
        ], $request);

        return (new PersonResource($this->loadPersonCard($person)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdatePersonRequest $request, Person $person): PersonResource
    {
        $old = $this->safePersonSnapshot($person);

        // Карточка человека — единственное место, где общее поле можно очистить:
        // здесь оператор видит всё, что меняет. Недостающее сервис добирает сам.
        $person = $this->people->updateSharedData($person, $request->validated());

        AuditLogService::log('Identity', 'person_updated', $person, $old, $this->safePersonSnapshot($person), $request);

        return new PersonResource($this->loadPersonCard($person));
    }

    public function duplicateCheck(DuplicatePersonCheckRequest $request, PersonDuplicateService $duplicates): array
    {
        $result = $duplicates->check($request->validated());

        AuditLogService::log('Identity', 'person_duplicate_check', ['type' => 'Person', 'id' => null], null, [
            'criteria' => $result['criteria'],
            'matches_count' => count($result['matches']),
            'matched_person_ids' => collect($result['matches'])->pluck('person.id')->values()->all(),
        ], $request);

        return [
            'data' => [
                'has_matches' => count($result['matches']) > 0,
                'matches_count' => count($result['matches']),
                'criteria' => $result['criteria'],
                'matches' => collect($result['matches'])->map(fn (array $match): array => [
                    'matched_by' => $match['matched_by'],
                    'person' => (new PersonResource($match['person']))->resolve($request),
                ])->values(),
            ],
        ];
    }

    /**
     * Разбор перед слиянием: что переедет, что дозаполнится и что мешает.
     *
     * Отдельной ручкой, а не флагом у слияния: показать и выполнить — разные
     * действия с разной ценой ошибки, и путать их в одном адресе не стоит.
     */
    public function mergePreview(MergePeopleRequest $request, PersonMergeService $merge): array
    {
        [$survivor, $absorbed] = $this->mergePair($request);

        return ['data' => $merge->plan($survivor, $absorbed)];
    }

    /**
     * Сливает две карточки. Обратного хода нет, поэтому событие пишется в
     * журнал аудита целиком: кто, кого, к кому и что при этом переехало.
     */
    public function merge(MergePeopleRequest $request, PersonMergeService $merge): JsonResponse
    {
        [$survivor, $absorbed] = $this->mergePair($request);

        $before = [
            'survivor' => ['id' => $survivor->id, 'name' => $survivor->full_name],
            'absorbed' => ['id' => $absorbed->id, 'name' => $absorbed->full_name],
        ];

        try {
            $result = $merge->merge($survivor, $absorbed);
        } catch (RuntimeException $exception) {
            // Отказ сервиса — это не поломка, а несогласие сливать: причина
            // написана по-русски и обязана дойти до человека целиком.
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        AuditLogService::log('Identity', 'person_merge', ['type' => 'Person', 'id' => $survivor->id], $before, $result, $request);

        return response()->json([
            'message' => 'Карточки объединены.',
            'data' => [...$result, 'person' => new PersonResource($this->loadPersonCard($survivor->refresh()))],
        ]);
    }

    /**
     * @return array{0: Person, 1: Person}
     */
    private function mergePair(MergePeopleRequest $request): array
    {
        return [
            Person::query()->findOrFail($request->integer('survivor_id')),
            Person::query()->findOrFail($request->integer('absorbed_id')),
        ];
    }

    public function profiles(Person $person, PersonService $personService): array
    {
        return [
            'data' => collect($personService->profiles($person))->map(fn ($items, string $type) => [
                'type' => Str::headline($type),
                'code' => $type,
                'count' => $items->count(),
                'items' => $items->values(),
            ])->values(),
        ];
    }

    private function loadPersonCard(Person $person): Person
    {
        return $person->load([
            'students.group',
            'teachers.subjects',
            'employees.primaryDepartment',
            'employees.primaryPosition',
            'applicants.status',
            'applicants.source',
            'applicantApplications.educationProgram',
            'graduates.student',
            'graduates.group',
            'graduates.diploma',
            'users.roles',
            'digitalIdentities',
            'currentRfidCard',
        ])->loadCount(['students', 'teachers', 'employees', 'applicants', 'applicantApplications', 'graduates', 'users', 'digitalIdentities']);
    }

    /** @return array<string, mixed> */
    private function safePersonSnapshot(Person $person): array
    {
        return [
            'id' => $person->id,
            'last_name' => $person->last_name,
            'first_name' => $person->first_name,
            'middle_name' => $person->middle_name,
            'birth_date' => $person->birth_date?->toDateString(),
            'gender' => $person->gender,
            'citizenship' => $person->citizenship,
            'place_birth' => $person->place_birth,
            'phone' => $person->phone,
            'email' => $person->email,
            'address' => $person->address,
            'has_snils_hash' => filled($person->snils_hash),
            'inn' => $person->inn,
            'status' => $person->status,
        ];
    }
}
