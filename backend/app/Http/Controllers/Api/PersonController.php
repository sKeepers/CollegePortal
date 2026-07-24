<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PersonResource;
use App\Models\Person;
use App\Services\PersonService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class PersonController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $operator = Person::query()->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $search = $request->string('search')->toString();
        $profile = $request->string('profile')->toString();

        $people = Person::query()
            ->withCount(['students', 'teachers', 'applicants', 'applicantApplications', 'graduates', 'users', 'digitalIdentities'])
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
                match ($profile) {
                    'student' => $query->has('students'),
                    'teacher' => $query->has('teachers'),
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
            'applicants.status',
            'applicants.source',
            'applicantApplications.educationProgram',
            'graduates.student',
            'graduates.group',
            'graduates.diploma',
            'users.roles',
            'digitalIdentities',
        ])->loadCount(['students', 'teachers', 'applicants', 'applicantApplications', 'graduates', 'users', 'digitalIdentities']));
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
}
