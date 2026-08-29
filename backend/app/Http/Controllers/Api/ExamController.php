<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExamRequest;
use App\Http\Requests\StoreExamResultRequest;
use App\Http\Requests\UpdateExamRequest;
use App\Http\Resources\ExamResource;
use App\Http\Resources\ExamResultResource;
use App\Models\Classroom;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Group;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use App\Support\Csv\CsvExport;
use App\Support\Csv\CsvImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Support\Http\PageSize;

class ExamController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $exams = Exam::query()
            ->with(['group', 'subject', 'teacher', 'classroom', 'results.student'])
            ->withCount('results')
            ->when($request->string('academic_year')->toString(), fn ($query, string $year) => $query->where('academic_year', $year))
            ->when($request->integer('group_id'), fn ($query, int $id) => $query->where('group_id', $id))
            ->when($request->integer('subject_id'), fn ($query, int $id) => $query->where('subject_id', $id))
            ->when($request->integer('teacher_id'), fn ($query, int $id) => $query->where('teacher_id', $id))
            ->when($request->string('exam_type')->toString(), fn ($query, string $type) => $query->where('exam_type', $type))
            ->orderByDesc('exam_date')
            ->orderBy('starts_at')
            ->paginate(PageSize::from($request, 50));

        return ExamResource::collection($exams);
    }

    public function store(StoreExamRequest $request): JsonResponse
    {
        $exam = Exam::create($this->normalizeExamData($request->validated()));

        return (new ExamResource($exam->load($this->relations())->loadCount('results')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Exam $exam): ExamResource
    {
        return new ExamResource($exam->load($this->relations())->loadCount('results'));
    }

    public function update(UpdateExamRequest $request, Exam $exam): ExamResource
    {
        $exam->update($this->normalizeExamPatch($request->validated()));

        return new ExamResource($exam->load($this->relations())->loadCount('results'));
    }

    public function destroy(Exam $exam): Response
    {
        $exam->delete();

        return response()->noContent();
    }

    public function storeResult(StoreExamResultRequest $request, Exam $exam): JsonResponse
    {
        $data = $request->validated();
        $result = $exam->results()->updateOrCreate(
            ['student_id' => (int) $data['student_id']],
            [
                'result' => $data['result'] ?? null,
                'score' => array_key_exists('score', $data) ? $data['score'] : null,
                'status' => $data['status'] ?? 'planned',
                'comment' => $data['comment'] ?? null,
            ]
        );

        return (new ExamResultResource($result->load('student')))
            ->response()
            ->setStatusCode($result->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function destroyResult(ExamResult $examResult): Response
    {
        $examResult->delete();

        return response()->noContent();
    }

    public function export(): StreamedResponse
    {
        $exams = Exam::query()->with($this->relations())->orderBy('id')->get();
        $filename = 'exams-'.now()->format('Ymd-His').'.csv';

        return CsvExport::download($filename, ['id', 'academic_year', 'semester', 'group_id', 'group_name', 'subject_id', 'subject_code', 'subject_name', 'teacher_id', 'teacher', 'classroom_id', 'classroom', 'exam_date', 'starts_at', 'ends_at', 'exam_type', 'status', 'topic', 'student_id', 'student', 'result', 'score', 'result_status', 'comment'], function (callable $row) use ($exams): void {
            foreach ($exams as $exam) {
                $results = $exam->results->isNotEmpty() ? $exam->results : collect([null]);
                foreach ($results as $result) {
                    $row([
                        $exam->id,
                        $exam->academic_year,
                        $exam->semester,
                        $exam->group_id,
                        $exam->group?->name,
                        $exam->subject_id,
                        $exam->subject?->code,
                        $exam->subject?->name,
                        $exam->teacher_id,
                        $this->teacherName($exam->teacher),
                        $exam->classroom_id,
                        $exam->classroom?->number,
                        $exam->exam_date?->toDateString(),
                        $this->formatTime($exam->starts_at),
                        $this->formatTime($exam->ends_at),
                        $exam->exam_type,
                        $exam->status,
                        $exam->topic,
                        $result?->student_id,
                        $this->studentName($result?->student),
                        $result?->result,
                        $result?->score,
                        $result?->status,
                        $result?->comment,
                    ]);
                }
            }
        });
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $created = 0; $updated = 0; $resultsCreated = 0; $errors = [];

        foreach (CsvImport::rows($request->file('file')->getRealPath()) as $line => $data) {
            $validator = Validator::make($data, [
                'academic_year' => ['required', 'string', 'max:20'],
                'semester' => ['required', 'integer', 'min:1', 'max:12'],
                'group_id' => ['nullable', 'integer', 'exists:groups,id'],
                'group_name' => ['nullable', 'string'],
                'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
                'subject_code' => ['nullable', 'string'],
                'subject_name' => ['nullable', 'string'],
                'teacher_id' => ['nullable', 'integer', 'exists:teachers,id'],
                'teacher' => ['nullable', 'string'],
                'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
                'classroom' => ['nullable', 'string'],
                // Колонка добавлена 30.08.2026 и необязательна: старые файлы без
                // неё грузятся по-прежнему. Нужна она с того дня, когда номера
                // аудиторий начнут повторяться в двух корпусах.
                'classroom_building' => ['nullable', 'string', 'max:255'],
                'exam_date' => ['required', 'date'],
                'starts_at' => ['nullable', 'date_format:H:i'],
                'ends_at' => ['nullable', 'date_format:H:i'],
                'exam_type' => ['required', 'in:exam,credit,differentiated_credit,gia'],
                'status' => ['nullable', 'in:draft,scheduled,completed,canceled'],
                'student_id' => ['nullable', 'integer', 'exists:students,id'],
                'student' => ['nullable', 'string'],
                'score' => ['nullable', 'integer', 'min:0', 'max:100'],
                'result_status' => ['nullable', 'in:planned,passed,failed,absent'],
            ]);
            if ($validator->fails()) { $errors[] = ['line' => $line, 'errors' => $validator->errors()->all()]; continue; }

            try {
                DB::transaction(function () use ($data, &$created, &$updated, &$resultsCreated): void {
                    $groupId = $this->resolveGroupId($data);
                    $subjectId = $this->resolveSubjectId($data);
                    $teacherId = $this->resolveTeacherId($data);
                    if (!$groupId) { throw new \RuntimeException('Группа не найдена.'); }
                    if (!$subjectId) { throw new \RuntimeException('Дисциплина не найдена.'); }
                    if (!$teacherId) { throw new \RuntimeException('Преподаватель не найден.'); }

                    $payload = $this->normalizeExamData([
                        'academic_year' => $data['academic_year'],
                        'semester' => $data['semester'],
                        'group_id' => $groupId,
                        'subject_id' => $subjectId,
                        'teacher_id' => $teacherId,
                        'classroom_id' => $this->resolveClassroomId($data),
                        'exam_date' => $data['exam_date'],
                        'starts_at' => $data['starts_at'] ?: null,
                        'ends_at' => $data['ends_at'] ?: null,
                        'exam_type' => $data['exam_type'],
                        'status' => $data['status'] ?: 'scheduled',
                        'topic' => $data['topic'] ?? null,
                    ]);

                    $exam = !empty($data['id']) ? Exam::find($data['id']) : null;
                    if ($exam) { $exam->update($payload); $updated++; }
                    else { $exam = Exam::create($payload); $created++; }

                    $studentId = $this->resolveStudentId($data);
                    if ($studentId) {
                        $result = $exam->results()->updateOrCreate(
                            ['student_id' => $studentId],
                            ['result' => $data['result'] ?: null, 'score' => $data['score'] !== '' ? (int) $data['score'] : null, 'status' => $data['result_status'] ?: 'planned', 'comment' => $data['comment'] ?? null]
                        );
                        if ($result->wasRecentlyCreated) { $resultsCreated++; }
                    }
                });
            } catch (\Throwable $exception) {
                $errors[] = ['line' => $line, 'errors' => [$exception->getMessage()]];
            }
        }

        return response()->json(['data' => compact('created', 'updated', 'resultsCreated', 'errors')]);
    }

    private function normalizeExamData(array $data): array
    {
        return [
            'academic_year' => trim($data['academic_year']),
            'semester' => (int) $data['semester'],
            'group_id' => (int) $data['group_id'],
            'subject_id' => (int) $data['subject_id'],
            'teacher_id' => (int) $data['teacher_id'],
            'classroom_id' => $data['classroom_id'] ? (int) $data['classroom_id'] : null,
            'exam_date' => $data['exam_date'],
            'starts_at' => $this->normalizeTime($data['starts_at'] ?? null),
            'ends_at' => $this->normalizeTime($data['ends_at'] ?? null),
            'exam_type' => $data['exam_type'],
            'status' => $data['status'] ?: 'scheduled',
            'topic' => $data['topic'] ?? null,
        ];
    }

    private function normalizeExamPatch(array $data): array
    {
        $patch = [];
        foreach (['academic_year', 'semester', 'group_id', 'subject_id', 'teacher_id', 'classroom_id', 'exam_date', 'starts_at', 'ends_at', 'exam_type', 'status', 'topic'] as $field) {
            if (array_key_exists($field, $data)) { $patch[$field] = $data[$field]; }
        }
        if (array_key_exists('academic_year', $patch)) { $patch['academic_year'] = trim($patch['academic_year']); }
        foreach (['semester', 'group_id', 'subject_id', 'teacher_id'] as $field) {
            if (array_key_exists($field, $patch)) { $patch[$field] = (int) $patch[$field]; }
        }
        if (array_key_exists('classroom_id', $patch)) { $patch['classroom_id'] = $patch['classroom_id'] ? (int) $patch['classroom_id'] : null; }
        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $patch)) { $patch[$field] = $this->normalizeTime($patch[$field]); }
        }
        if (array_key_exists('status', $patch) && !$patch['status']) { $patch['status'] = 'scheduled'; }
        return $patch;
    }

    private function normalizeTime(?string $value): ?string
    {
        return $value ? substr($value, 0, 5) : null;
    }

    private function formatTime(mixed $value): ?string
    {
        return $value ? substr((string) $value, 0, 5) : null;
    }

    private function teacherName(?Teacher $teacher): string
    {
        return trim(implode(' ', array_filter([$teacher?->last_name, $teacher?->first_name, $teacher?->middle_name])));
    }

    private function studentName(?Student $student): string
    {
        return trim(implode(' ', array_filter([$student?->last_name, $student?->first_name, $student?->middle_name])));
    }

    private function resolveGroupId(array $data): ?int
    {
        if (!empty($data['group_id'])) { return (int) $data['group_id']; }
        if (!empty($data['group_name'])) { return Group::where('name', $data['group_name'])->value('id'); }
        return null;
    }

    private function resolveSubjectId(array $data): ?int
    {
        if (!empty($data['subject_id'])) { return (int) $data['subject_id']; }
        if (!empty($data['subject_code'])) { return Subject::where('code', $data['subject_code'])->value('id'); }
        if (!empty($data['subject_name'])) { return Subject::where('name', $data['subject_name'])->value('id'); }
        return null;
    }

    private function resolveTeacherId(array $data): ?int
    {
        if (!empty($data['teacher_id'])) { return (int) $data['teacher_id']; }
        if (!empty($data['teacher'])) {
            $name = mb_strtolower(trim($data['teacher']));
            return Teacher::query()->get()->first(fn (Teacher $teacher) => mb_strtolower($this->teacherName($teacher)) === $name)?->id;
        }
        return null;
    }

    /**
     * Аудитория ищется парой «номер + корпус», а спор не решается наугад.
     *
     * До 30.08.2026 здесь стояло `Classroom::where('number', ...)->value('id')`
     * — корпус не учитывался **вовсе**, даже когда был известен, и при двух
     * аудиториях «101» экзамен назначался в ту, что попалась первой. Молча:
     * строка импорта проходила, ошибки не было, а место экзамена оказывалось
     * в другом корпусе.
     *
     * Повод срочный: 30.08.2026 в портал приходят аудитории Голенева и Серова,
     * и номера в них повторяются. Пока корпус один, промаха нет физически.
     *
     * Способ взят из портала, а не выдуман: `ClassroomImportHandler` и
     * `ClassroomCsvService` ищут той же парой, и уникальность в правилах
     * объявлена парой же. Из трёх мест, ищущих аудиторию, два делали это верно.
     *
     * **Неоднозначность отказывает, а не выбирает.** Ручка — пакетный импорт,
     * и отказ здесь стоит дёшево: строка попадает в `errors` со своим номером,
     * остальные грузятся дальше. Тихий промах стоил бы дороже — он обнаружился
     * бы у двери аудитории.
     */
    private function resolveClassroomId(array $data): ?int
    {
        if (!empty($data['classroom_id'])) { return (int) $data['classroom_id']; }

        $number = trim((string) ($data['classroom'] ?? ''));

        if ($number === '') { return null; }

        $building = trim((string) ($data['classroom_building'] ?? ''));

        $found = Classroom::query()
            ->where('number', $number)
            ->when($building !== '', fn ($query) => $query->where('building', $building))
            ->orderBy('id')
            ->get(['id', 'building']);

        if ($found->count() > 1) {
            $buildings = $found->pluck('building')->map(fn ($name) => $name ?: 'без корпуса')->implode(', ');

            throw new \RuntimeException(
                "Аудитория «{$number}» есть в нескольких корпусах ({$buildings}). "
                .'Укажите колонку `classroom_building` или `classroom_id`.',
            );
        }

        return $found->first()?->id;
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

    private function relations(): array
    {
        return ['group', 'subject', 'teacher', 'classroom', 'results.student'];
    }
}
