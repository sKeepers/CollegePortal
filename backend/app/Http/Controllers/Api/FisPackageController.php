<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFisPackageRequest;
use App\Http\Resources\FisPackageResource;
use App\Models\ApplicantApplication;
use App\Models\Exam;
use App\Models\FisPackage;
use App\Models\Graduate;
use App\Models\Student;
use App\Services\Admissions\AdmissionDocumentReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FisPackageController extends Controller
{
    public function __construct(private readonly AdmissionDocumentReadinessService $readiness)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $packages = FisPackage::query()
            ->with(['educationProgram', 'records.applicantApplication.educationProgram.specialty', 'records.exam.subject', 'records.graduate.student', 'records.validationErrors', 'validationErrors'])
            ->withCount(['records', 'validationErrors'])
            ->when($request->string('package_type')->toString(), fn ($query, string $type) => $query->where('package_type', $type))
            ->when($request->integer('year'), fn ($query, int $year) => $query->where('year', $year))
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->integer('education_program_id'), fn ($query, int $id) => $query->where('education_program_id', $id))
            ->orderByDesc('created_at')
            ->paginate(50);

        return FisPackageResource::collection($packages);
    }

    public function store(StoreFisPackageRequest $request): JsonResponse
    {
        $data = $request->validated();
        $package = DB::transaction(function () use ($data): FisPackage {
            $typeLabel = $data['package_type'] === 'admission' ? 'ФИС Приема' : 'ФИС ГИА';
            $package = FisPackage::create(['name' => ($data['name'] ?? null) ?: $typeLabel.' '.$data['year'], 'package_type' => $data['package_type'], 'year' => (int) $data['year'], 'education_program_id' => $data['education_program_id'] ?? null, 'status' => 'draft', 'note' => $data['note'] ?? null]);
            $package->package_type === 'admission' ? $this->fillAdmissionRecords($package) : $this->fillGiaRecords($package);
            return $package;
        });

        return (new FisPackageResource($this->freshPackage($package)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(FisPackage $fisPackage): FisPackageResource
    {
        return new FisPackageResource($this->freshPackage($fisPackage));
    }

    public function validatePackage(FisPackage $fisPackage): FisPackageResource
    {
        DB::transaction(function () use ($fisPackage): void {
            $fisPackage->validationErrors()->delete();
            $fisPackage->load($this->recordRelations());
            foreach ($fisPackage->records as $record) {
                $errors = $fisPackage->package_type === 'admission'
                    ? $this->validateAdmissionRecord($record->applicantApplication)
                    : $this->validateGiaRecord($record->exam, $record->graduate);
                foreach ($errors as [$field, $message]) {
                    $fisPackage->validationErrors()->create(['fis_record_id' => $record->id, 'field' => $field, 'message' => $message, 'severity' => 'error']);
                }
                $record->update(['status' => $errors ? 'invalid' : 'valid', 'payload' => $this->payloadForRecord($fisPackage->package_type, $record)]);
            }
            $hasErrors = $fisPackage->validationErrors()->exists();
            $fisPackage->update(['status' => $hasErrors ? 'validation_failed' : 'ready', 'validation_checked_at' => now()]);
        });

        return new FisPackageResource($this->freshPackage($fisPackage));
    }

    public function markExported(FisPackage $fisPackage): FisPackageResource
    {
        // Выгрузка в ФИС ГИА — операция по закону. Пакет, не прошедший проверку
        // (в том числе из-за неполной карточки студента), выгружать нельзя.
        if ($fisPackage->status !== 'ready') {
            throw ValidationException::withMessages([
                'status' => 'Пакет не прошёл проверку. Выгрузка в ФИС ГИА заблокирована до устранения ошибок.',
            ]);
        }

        $fisPackage->update(['status' => 'exported', 'exported_at' => now()]);
        $fisPackage->records()->update(['status' => 'exported']);
        return new FisPackageResource($this->freshPackage($fisPackage));
    }

    public function archive(FisPackage $fisPackage): FisPackageResource
    {
        $fisPackage->update(['status' => 'archived', 'archived_at' => now()]);
        return new FisPackageResource($this->freshPackage($fisPackage));
    }

    public function exportCsv(FisPackage $fisPackage): StreamedResponse
    {
        $package = $this->freshPackage($fisPackage);
        return response()->streamDownload(function () use ($package): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['record_id', 'type', 'person', 'birth_date', 'program', 'specialty', 'status', 'details'], ';');
            foreach ($package->records as $record) {
                $p = $record->payload ?: [];
                fputcsv($output, [$record->id, $package->package_type, $p['person'] ?? null, $p['birth_date'] ?? null, $p['education_program'] ?? null, $p['specialty'] ?? null, $record->status, $p['details'] ?? null], ';');
            }
            fclose($output);
        }, 'fis-package-'.$package->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportJson(FisPackage $fisPackage): JsonResponse
    {
        $package = $this->freshPackage($fisPackage);
        return response()->json(['package' => ['id' => $package->id, 'type' => $package->package_type, 'year' => $package->year, 'status' => $package->status], 'records' => $package->records->map(fn ($record) => ['id' => $record->id, 'status' => $record->status, 'payload' => $record->payload])->values()]);
    }

    private function fillAdmissionRecords(FisPackage $package): void
    {
        ApplicantApplication::query()->legacy()->with('educationProgram.specialty')
            ->when($package->education_program_id, fn ($query, int $id) => $query->where('education_program_id', $id))
            ->whereYear('submitted_at', $package->year)
            ->get()->each(fn (ApplicantApplication $app) => $package->records()->create(['applicant_application_id' => $app->id, 'education_program_id' => $app->education_program_id, 'specialty_id' => $app->educationProgram?->specialty_id, 'status' => 'draft', 'payload' => $this->admissionPayload($app)]));
    }

    private function fillGiaRecords(FisPackage $package): void
    {
        Exam::query()->with(['group.educationProgram.specialty', 'subject', 'teacher', 'results.student'])
            ->where('exam_type', 'gia')
            ->whereYear('exam_date', $package->year)
            ->when($package->education_program_id, fn ($query, int $id) => $query->whereHas('group', fn ($group) => $group->where('education_program_id', $id)))
            ->get()->each(function (Exam $exam) use ($package): void {
                $graduate = Graduate::where('group_id', $exam->group_id)->where('graduation_year', $package->year)->first();
                $package->records()->create(['exam_id' => $exam->id, 'graduate_id' => $graduate?->id, 'education_program_id' => $exam->group?->education_program_id, 'specialty_id' => $exam->group?->educationProgram?->specialty_id, 'status' => 'draft', 'payload' => $this->giaPayload($exam, $graduate)]);
            });
    }

    private function validateAdmissionRecord(?ApplicantApplication $app): array
    {
        $checks = [['person', $this->applicantName($app), 'Не заполнено ФИО абитуриента'], ['birth_date', $app?->birth_date?->toDateString(), 'Не заполнена дата рождения'], ['education_program', $app?->educationProgram?->name, 'Не указана образовательная программа'], ['specialty', $app?->educationProgram?->specialty?->name, 'Не указана специальность'], ['status', $app?->status, 'Не указан статус заявления'], ['submitted_at', $app?->submitted_at?->toDateString(), 'Не указана дата подачи']];
        return collect($checks)->filter(fn ($item) => blank($item[1]))->map(fn ($item) => [$item[0], $item[2]])->values()->all();
    }

    private function validateGiaRecord(?Exam $exam, ?Graduate $graduate): array
    {
        $checks = [['exam', $exam?->id, 'Не указан экзамен/ГИА'], ['exam_date', $exam?->exam_date?->toDateString(), 'Не указана дата ГИА'], ['subject', $exam?->subject?->name, 'Не указана дисциплина/предмет'], ['group', $exam?->group?->name, 'Не указана группа'], ['education_program', $exam?->group?->educationProgram?->name ?: $graduate?->educationProgram?->name, 'Не указана образовательная программа'], ['specialty', $exam?->group?->educationProgram?->specialty?->name ?: $graduate?->specialty?->name, 'Не указана специальность']];
        $errors = collect($checks)->filter(fn ($item) => blank($item[1]))->map(fn ($item) => [$item[0], $item[2]])->values()->all();

        // Выгрузка в ФИС ГИА требует паспорта, документа об образовании и СНИЛС.
        // Пакет по группе без выпускника проверять нечего — карточку смотрим,
        // только когда запись относится к конкретному человеку.
        return $graduate?->student
            ? array_merge($errors, $this->studentCardErrors($graduate->student))
            : $errors;
    }

    /** @return list<array{0:string,1:string}> */
    private function studentCardErrors(Student $student): array
    {
        $card = $this->readiness->forStudent($student);

        if ($card['complete']) {
            return [];
        }

        return array_map(
            fn (array $reason): array => ['student_card.'.$reason['field'], 'Неполная карточка студента: '.$reason['message']],
            $card['blocking_reasons_detailed'],
        );
    }

    private function payloadForRecord(string $type, mixed $record): array
    {
        return $type === 'admission' ? $this->admissionPayload($record->applicantApplication) : $this->giaPayload($record->exam, $record->graduate);
    }

    private function admissionPayload(?ApplicantApplication $app): array
    {
        return ['person' => $this->applicantName($app), 'birth_date' => $app?->birth_date?->toDateString(), 'education_program' => $app?->educationProgram?->name, 'specialty' => $app?->educationProgram?->specialty?->name, 'status' => $app?->status, 'submitted_at' => $app?->submitted_at?->toDateString(), 'details' => $app?->email ?: $app?->phone];
    }

    private function giaPayload(?Exam $exam, ?Graduate $graduate): array
    {
        return ['person' => $graduate ? trim(implode(' ', array_filter([$graduate->student?->last_name, $graduate->student?->first_name, $graduate->student?->middle_name]))) : ($exam?->group?->name ?: 'ГИА'), 'birth_date' => $graduate?->student?->birth_date?->toDateString(), 'education_program' => $exam?->group?->educationProgram?->name ?: $graduate?->educationProgram?->name, 'specialty' => $exam?->group?->educationProgram?->specialty?->name ?: $graduate?->specialty?->name, 'status' => $exam?->status, 'submitted_at' => $exam?->exam_date?->toDateString(), 'details' => $exam?->subject?->name];
    }

    private function applicantName(?ApplicantApplication $app): string
    {
        return trim(implode(' ', array_filter([$app?->last_name, $app?->first_name, $app?->middle_name])));
    }

    private function recordRelations(): array
    {
        return ['records.applicantApplication.educationProgram.specialty', 'records.exam.group.educationProgram.specialty', 'records.exam.subject', 'records.graduate.student', 'records.graduate.educationProgram', 'records.graduate.specialty'];
    }

    private function freshPackage(FisPackage $package): FisPackage
    {
        return $package->fresh(['educationProgram', 'records.applicantApplication.educationProgram.specialty', 'records.exam.subject', 'records.exam.group.educationProgram.specialty', 'records.graduate.student', 'records.validationErrors', 'validationErrors'])->loadCount(['records', 'validationErrors']);
    }
}
