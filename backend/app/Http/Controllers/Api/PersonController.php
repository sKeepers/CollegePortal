<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DuplicatePersonCheckRequest;
use App\Http\Requests\StorePersonRequest;
use App\Http\Requests\UpdatePersonRequest;
use App\Http\Resources\PersonResource;
use App\Models\Person;
use App\Services\AuditLogService;
use App\Services\PersonDuplicateService;
use App\Services\PersonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

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
            ->paginate(30);

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
        $payload = array_merge([
            'uuid' => $person->uuid,
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
            'photo_path' => $person->photo_path,
            'snils' => $person->snils,
            'inn' => $person->inn,
            'status' => $person->status,
        ], $request->validated());

        $person = $this->people->updateSharedData($person, $payload);

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

    public function merge(): JsonResponse
    {
        return response()->json([
            'message' => 'merge_not_supported',
            'code' => 'merge_not_supported',
            'details' => 'Объединение Person будет реализовано отдельным безопасным этапом.',
        ], Response::HTTP_NOT_IMPLEMENTED);
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
