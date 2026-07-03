<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFrdoPackageRequest;
use App\Http\Resources\FrdoPackageResource;
use App\Models\FrdoPackage;
use App\Models\Graduate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FrdoPackageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $packages = FrdoPackage::query()
            ->with(['educationProgram', 'records.graduate.student', 'records.diploma', 'records.validationErrors', 'validationErrors'])
            ->withCount(['records', 'validationErrors'])
            ->when($request->integer('graduation_year'), fn ($query, int $year) => $query->where('graduation_year', $year))
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->integer('education_program_id'), fn ($query, int $id) => $query->where('education_program_id', $id))
            ->orderByDesc('created_at')
            ->paginate(50);

        return FrdoPackageResource::collection($packages);
    }

    public function store(StoreFrdoPackageRequest $request): JsonResponse
    {
        $data = $request->validated();
        $package = DB::transaction(function () use ($data): FrdoPackage {
            $package = FrdoPackage::create([
                'name' => ($data['name'] ?? null) ?: 'ФРДО '.$data['graduation_year'],
                'graduation_year' => (int) $data['graduation_year'],
                'education_program_id' => $data['education_program_id'] ?? null,
                'status' => 'draft',
                'note' => $data['note'] ?? null,
            ]);

            $graduates = Graduate::query()
                ->with(['student', 'group', 'educationProgram', 'specialty', 'diploma.supplement'])
                ->where('graduation_year', $package->graduation_year)
                ->when($package->education_program_id, fn ($query, int $id) => $query->where('education_program_id', $id))
                ->get();

            foreach ($graduates as $graduate) {
                $diploma = $graduate->diploma;
                $package->records()->create([
                    'graduate_id' => $graduate->id,
                    'diploma_id' => $diploma?->id,
                    'diploma_supplement_id' => $diploma?->supplement?->id,
                    'education_program_id' => $graduate->education_program_id,
                    'specialty_id' => $graduate->specialty_id,
                    'status' => 'draft',
                    'payload' => $this->payloadFor($graduate),
                ]);
            }

            return $package;
        });

        return (new FrdoPackageResource($this->freshPackage($package)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(FrdoPackage $frdoPackage): FrdoPackageResource
    {
        return new FrdoPackageResource($this->freshPackage($frdoPackage));
    }

    public function validatePackage(FrdoPackage $frdoPackage): FrdoPackageResource
    {
        DB::transaction(function () use ($frdoPackage): void {
            $frdoPackage->validationErrors()->delete();
            $frdoPackage->load(['records.graduate.student', 'records.graduate.educationProgram', 'records.graduate.specialty', 'records.diploma.supplement']);
            foreach ($frdoPackage->records as $record) {
                $graduate = $record->graduate;
                $diploma = $record->diploma;
                $errors = $this->validateRecord($graduate, $diploma);
                foreach ($errors as [$field, $message]) {
                    $frdoPackage->validationErrors()->create(['frdo_record_id' => $record->id, 'field' => $field, 'message' => $message, 'severity' => 'error']);
                }
                $record->update(['status' => $errors ? 'invalid' : 'valid', 'payload' => $this->payloadFor($graduate)]);
            }
            $hasErrors = $frdoPackage->validationErrors()->exists();
            $frdoPackage->update(['status' => $hasErrors ? 'validation_failed' : 'ready', 'validation_checked_at' => now()]);
        });

        return new FrdoPackageResource($this->freshPackage($frdoPackage));
    }

    public function markExported(FrdoPackage $frdoPackage): FrdoPackageResource
    {
        $frdoPackage->update(['status' => 'exported', 'exported_at' => now()]);
        $frdoPackage->records()->update(['status' => 'exported']);

        return new FrdoPackageResource($this->freshPackage($frdoPackage));
    }

    public function archive(FrdoPackage $frdoPackage): FrdoPackageResource
    {
        $frdoPackage->update(['status' => 'archived', 'archived_at' => now()]);

        return new FrdoPackageResource($this->freshPackage($frdoPackage));
    }

    public function exportCsv(FrdoPackage $frdoPackage): StreamedResponse
    {
        $package = $this->freshPackage($frdoPackage);
        $filename = 'frdo-package-'.$package->id.'.csv';

        return response()->streamDownload(function () use ($package): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['record_id', 'graduate_id', 'student', 'birth_date', 'specialty', 'education_program', 'diploma_series', 'diploma_number', 'registration_number', 'issue_date', 'qualification', 'diploma_status', 'record_status'], ';');
            foreach ($package->records as $record) {
                $p = $record->payload ?: [];
                fputcsv($output, [$record->id, $record->graduate_id, $p['student'] ?? null, $p['birth_date'] ?? null, $p['specialty'] ?? null, $p['education_program'] ?? null, $p['diploma_series'] ?? null, $p['diploma_number'] ?? null, $p['registration_number'] ?? null, $p['issue_date'] ?? null, $p['qualification'] ?? null, $p['diploma_status'] ?? null, $record->status], ';');
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportJson(FrdoPackage $frdoPackage): JsonResponse
    {
        $package = $this->freshPackage($frdoPackage);
        return response()->json([
            'package' => ['id' => $package->id, 'name' => $package->name, 'graduation_year' => $package->graduation_year, 'status' => $package->status],
            'records' => $package->records->map(fn ($record) => ['id' => $record->id, 'status' => $record->status, 'payload' => $record->payload])->values(),
        ]);
    }

    private function validateRecord(?Graduate $graduate, mixed $diploma): array
    {
        $student = $graduate?->student;
        $checks = [
            ['student', trim(implode(' ', array_filter([$student?->last_name, $student?->first_name, $student?->middle_name]))), 'Не заполнено ФИО выпускника'],
            ['birth_date', $student?->birth_date?->toDateString(), 'Не заполнена дата рождения выпускника'],
            ['specialty', $graduate?->specialty?->name, 'Не указана специальность'],
            ['education_program', $graduate?->educationProgram?->name, 'Не указана образовательная программа'],
            ['diploma_series', $diploma?->series, 'Не указана серия диплома'],
            ['diploma_number', $diploma?->number, 'Не указан номер диплома'],
            ['registration_number', $diploma?->registration_number, 'Не указан регистрационный номер'],
            ['issue_date', $diploma?->issue_date?->toDateString(), 'Не указана дата выдачи диплома'],
            ['qualification', $diploma?->qualification ?: $graduate?->qualification, 'Не указана квалификация'],
            ['diploma_status', $diploma?->status, 'Не указан статус диплома'],
        ];

        return collect($checks)->filter(fn ($item) => blank($item[1]))->map(fn ($item) => [$item[0], $item[2]])->values()->all();
    }

    private function payloadFor(Graduate $graduate): array
    {
        $graduate->loadMissing(['student', 'educationProgram', 'specialty', 'diploma.supplement']);
        $student = $graduate->student;
        $diploma = $graduate->diploma;
        return [
            'student' => trim(implode(' ', array_filter([$student?->last_name, $student?->first_name, $student?->middle_name]))),
            'birth_date' => $student?->birth_date?->toDateString(),
            'specialty' => $graduate->specialty?->name,
            'specialty_code' => $graduate->specialty?->code,
            'education_program' => $graduate->educationProgram?->name,
            'diploma_series' => $diploma?->series,
            'diploma_number' => $diploma?->number,
            'registration_number' => $diploma?->registration_number,
            'issue_date' => $diploma?->issue_date?->toDateString(),
            'qualification' => $diploma?->qualification ?: $graduate->qualification,
            'diploma_status' => $diploma?->status,
            'supplement_series' => $diploma?->supplement?->series,
            'supplement_number' => $diploma?->supplement?->number,
        ];
    }

    private function freshPackage(FrdoPackage $package): FrdoPackage
    {
        return $package->fresh(['educationProgram', 'records.graduate.student.group', 'records.graduate.educationProgram', 'records.graduate.specialty', 'records.diploma.supplement', 'records.validationErrors', 'validationErrors'])->loadCount(['records', 'validationErrors']);
    }
}
