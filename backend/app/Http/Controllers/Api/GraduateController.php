<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDiplomaRequest;
use App\Http\Requests\StoreDiplomaSupplementRequest;
use App\Http\Requests\StoreGraduateRequest;
use App\Http\Requests\UpdateGraduateRequest;
use App\Http\Resources\DiplomaResource;
use App\Http\Resources\DiplomaSupplementResource;
use App\Http\Resources\GraduateResource;
use App\Models\EducationProgram;
use App\Models\Graduate;
use App\Models\Group;
use App\Models\Specialty;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GraduateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $graduates = Graduate::query()
            ->with($this->relations())
            ->when($request->integer('graduation_year'), fn ($query, int $year) => $query->where('graduation_year', $year))
            ->when($request->integer('group_id'), fn ($query, int $id) => $query->where('group_id', $id))
            ->when($request->integer('education_program_id'), fn ($query, int $id) => $query->where('education_program_id', $id))
            ->when($request->string('diploma_status')->toString(), fn ($query, string $status) => $query->whereHas('diploma', fn ($diploma) => $diploma->where('status', $status)))
            ->orderByDesc('graduation_year')
            ->orderBy('id')
            ->paginate(50);

        return GraduateResource::collection($graduates);
    }

    public function store(StoreGraduateRequest $request): JsonResponse
    {
        $graduate = Graduate::create($this->normalizeGraduateData($request->validated()));

        return (new GraduateResource($graduate->load($this->relations())))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Graduate $graduate): GraduateResource
    {
        return new GraduateResource($graduate->load($this->relations()));
    }

    public function update(UpdateGraduateRequest $request, Graduate $graduate): GraduateResource
    {
        $graduate->update($this->normalizeGraduatePatch($request->validated()));

        return new GraduateResource($graduate->load($this->relations()));
    }

    public function destroy(Graduate $graduate): Response
    {
        $graduate->delete();

        return response()->noContent();
    }

    public function storeDiploma(StoreDiplomaRequest $request, Graduate $graduate): JsonResponse
    {
        $diploma = $graduate->diploma()->updateOrCreate(['graduate_id' => $graduate->id], $this->normalizeDiplomaData($request->validated(), $graduate));

        return (new DiplomaResource($diploma->load('supplement')))
            ->response()
            ->setStatusCode($diploma->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function storeSupplement(StoreDiplomaSupplementRequest $request, Graduate $graduate): JsonResponse
    {
        $diploma = $graduate->diploma()->firstOrCreate(['graduate_id' => $graduate->id], ['qualification' => $graduate->qualification, 'status' => 'draft']);
        $supplement = $diploma->supplement()->updateOrCreate(['diploma_id' => $diploma->id], $request->validated());

        return (new DiplomaSupplementResource($supplement))
            ->response()
            ->setStatusCode($supplement->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function export(): StreamedResponse
    {
        $graduates = Graduate::query()->with($this->relations())->orderBy('id')->get();
        $filename = 'graduates-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($graduates): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['id', 'student_id', 'student', 'group_id', 'group_name', 'education_program_id', 'education_program', 'specialty_id', 'specialty', 'graduation_year', 'qualification', 'status', 'diploma_series', 'diploma_number', 'registration_number', 'issue_date', 'gia_decision', 'diploma_status', 'supplement_series', 'supplement_number', 'supplement_status', 'note'], ';');
            foreach ($graduates as $graduate) {
                fputcsv($output, [
                    $graduate->id,
                    $graduate->student_id,
                    $this->studentName($graduate->student),
                    $graduate->group_id,
                    $graduate->group?->name,
                    $graduate->education_program_id,
                    $graduate->educationProgram?->name,
                    $graduate->specialty_id,
                    $graduate->specialty?->name,
                    $graduate->graduation_year,
                    $graduate->qualification,
                    $graduate->status,
                    $graduate->diploma?->series,
                    $graduate->diploma?->number,
                    $graduate->diploma?->registration_number,
                    $graduate->diploma?->issue_date?->toDateString(),
                    $graduate->diploma?->gia_decision,
                    $graduate->diploma?->status,
                    $graduate->diploma?->supplement?->series,
                    $graduate->diploma?->supplement?->number,
                    $graduate->diploma?->supplement?->status,
                    $graduate->note,
                ], ';');
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $headers = fgetcsv($handle, 0, ';') ?: [];
        $created = 0; $updated = 0; $diplomasSaved = 0; $errors = []; $line = 1;

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $line++;
            $data = array_combine($headers, array_pad($row, count($headers), '')) ?: [];
            $validator = Validator::make($data, [
                'student_id' => ['nullable', 'integer', 'exists:students,id'],
                'student' => ['nullable', 'string'],
                'group_id' => ['nullable', 'integer', 'exists:groups,id'],
                'group_name' => ['nullable', 'string'],
                'education_program_id' => ['nullable', 'integer', 'exists:education_programs,id'],
                'education_program' => ['nullable', 'string'],
                'specialty_id' => ['nullable', 'integer', 'exists:specialties,id'],
                'specialty' => ['nullable', 'string'],
                'graduation_year' => ['required', 'integer', 'min:2000', 'max:2100'],
                'status' => ['nullable', 'in:draft,ready,issued,archived'],
                'diploma_status' => ['nullable', 'in:draft,ready,issued,revoked'],
                'issue_date' => ['nullable', 'date'],
            ]);
            if ($validator->fails()) { $errors[] = ['line' => $line, 'errors' => $validator->errors()->all()]; continue; }

            try {
                DB::transaction(function () use ($data, &$created, &$updated, &$diplomasSaved): void {
                    $studentId = $this->resolveStudentId($data);
                    if (!$studentId) { throw new \RuntimeException('Студент не найден.'); }
                    $student = Student::with('group.educationProgram.specialty')->findOrFail($studentId);
                    $payload = $this->normalizeGraduateData([
                        'student_id' => $studentId,
                        'group_id' => $this->resolveGroupId($data) ?: $student->group_id,
                        'education_program_id' => $this->resolveEducationProgramId($data) ?: $student->group?->education_program_id,
                        'specialty_id' => $this->resolveSpecialtyId($data) ?: $student->group?->educationProgram?->specialty_id,
                        'graduation_year' => $data['graduation_year'],
                        'qualification' => $data['qualification'] ?? $student->group?->educationProgram?->specialty?->qualification,
                        'status' => $data['status'] ?: 'draft',
                        'note' => $data['note'] ?? null,
                    ]);
                    $graduate = Graduate::where('student_id', $studentId)->first();
                    if ($graduate) { $graduate->update($payload); $updated++; }
                    else { $graduate = Graduate::create($payload); $created++; }

                    if (!empty($data['diploma_series']) || !empty($data['diploma_number']) || !empty($data['registration_number'])) {
                        $graduate->diploma()->updateOrCreate(['graduate_id' => $graduate->id], [
                            'series' => $data['diploma_series'] ?? null,
                            'number' => $data['diploma_number'] ?? null,
                            'registration_number' => $data['registration_number'] ?? null,
                            'issue_date' => $data['issue_date'] ?: null,
                            'qualification' => $data['qualification'] ?? $graduate->qualification,
                            'gia_decision' => $data['gia_decision'] ?? null,
                            'status' => $data['diploma_status'] ?: 'draft',
                        ]);
                        $diplomasSaved++;
                    }
                });
            } catch (\Throwable $exception) {
                $errors[] = ['line' => $line, 'errors' => [$exception->getMessage()]];
            }
        }
        fclose($handle);

        return response()->json(['data' => compact('created', 'updated', 'diplomasSaved', 'errors')]);
    }

    private function normalizeGraduateData(array $data): array
    {
        $student = Student::with('group.educationProgram.specialty')->find($data['student_id']);
        return [
            'student_id' => (int) $data['student_id'],
            'group_id' => $data['group_id'] ? (int) $data['group_id'] : $student?->group_id,
            'education_program_id' => $data['education_program_id'] ? (int) $data['education_program_id'] : $student?->group?->education_program_id,
            'specialty_id' => $data['specialty_id'] ? (int) $data['specialty_id'] : $student?->group?->educationProgram?->specialty_id,
            'graduation_year' => (int) $data['graduation_year'],
            'qualification' => $data['qualification'] ?? $student?->group?->educationProgram?->specialty?->qualification,
            'status' => $data['status'] ?: 'draft',
            'note' => $data['note'] ?? null,
        ];
    }

    private function normalizeGraduatePatch(array $data): array
    {
        foreach (['student_id', 'group_id', 'education_program_id', 'specialty_id', 'graduation_year'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') { $data[$field] = (int) $data[$field]; }
        }
        if (array_key_exists('status', $data) && !$data['status']) { $data['status'] = 'draft'; }
        return $data;
    }

    private function normalizeDiplomaData(array $data, Graduate $graduate): array
    {
        return [
            'series' => $data['series'] ?? null,
            'number' => $data['number'] ?? null,
            'registration_number' => $data['registration_number'] ?? null,
            'issue_date' => $data['issue_date'] ?? null,
            'qualification' => $data['qualification'] ?? $graduate->qualification,
            'gia_decision' => $data['gia_decision'] ?? null,
            'status' => $data['status'] ?? 'draft',
            'note' => $data['note'] ?? null,
        ];
    }

    private function studentName(?Student $student): string
    {
        return trim(implode(' ', array_filter([$student?->last_name, $student?->first_name, $student?->middle_name])));
    }

    private function resolveStudentId(array $data): ?int
    {
        if (!empty($data['student_id'])) { return (int) $data['student_id']; }
        if (!empty($data['student'])) {
            $name = mb_strtolower(trim($data['student']));
            return Student::query()->get()->first(fn (Student $student) => mb_strtolower($this->studentName($student)) === $name)?->id;
        }
        return null;
    }

    private function resolveGroupId(array $data): ?int
    {
        if (!empty($data['group_id'])) { return (int) $data['group_id']; }
        if (!empty($data['group_name'])) { return Group::where('name', $data['group_name'])->value('id'); }
        return null;
    }

    private function resolveEducationProgramId(array $data): ?int
    {
        if (!empty($data['education_program_id'])) { return (int) $data['education_program_id']; }
        if (!empty($data['education_program'])) { return EducationProgram::where('name', $data['education_program'])->value('id'); }
        return null;
    }

    private function resolveSpecialtyId(array $data): ?int
    {
        if (!empty($data['specialty_id'])) { return (int) $data['specialty_id']; }
        if (!empty($data['specialty'])) { return Specialty::where('name', $data['specialty'])->value('id'); }
        return null;
    }

    private function relations(): array
    {
        return ['student.group', 'group.educationProgram.specialty', 'educationProgram.specialty', 'specialty', 'diploma.supplement'];
    }
}
