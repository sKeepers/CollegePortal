<?php

namespace App\Services\Import;

use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Services\AutoCodeService;
use App\Services\Import\Concerns\ResolvesImportRelations;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Загрузка учебных планов файлом.
 *
 * Строка файла — это одна дисциплина плана в одном семестре. Сам план заводится
 * или находится по коду, а если кода нет — по тройке «программа, название, год».
 *
 * **Пишет в `curriculum_subjects`, а не в `curriculum_items`.** До 23.08.2026
 * было наоборот, и это тихая ловушка: нагрузка строится из `CurriculumSubject`
 * (`TeachingLoadGenerationService` связывает `curriculum_subject_id`), и экраны
 * плана — `subjects`, `semesters`, `summary` — читают её же. Загруженный файлом
 * план выглядел заполненным, а нагрузка видела пустоту, и причина не была видна
 * ниоткуда. `curriculum_items` остаётся наследством: убирать его надо отдельно,
 * а не поддерживать вторым списком правды.
 */
class CurriculumImportHandler extends AbstractImportHandler
{
    use ResolvesImportRelations;

    public function __construct(private readonly AutoCodeService $autoCodeService)
    {
    }

    public function type(): string
    {
        return 'curricula';
    }

    public function label(): string
    {
        return 'Учебные планы';
    }

    public function modelClass(): string
    {
        return Curriculum::class;
    }

    public function keyFields(): array
    {
        return ['curriculum_code'];
    }

    public function fields(): array
    {
        return [
            'curriculum_code' => ['label' => 'Код учебного плана', 'required' => false, 'aliases' => ['код учебного плана', 'код плана', 'curriculum_code', 'code']],
            'curriculum_name' => ['label' => 'Учебный план', 'required' => true, 'aliases' => ['учебный план', 'название плана', 'curriculum_name', 'name']],
            'education_program_id' => ['label' => 'ID программы', 'required' => false, 'aliases' => ['education_program_id', 'id программы']],
            'education_program_name' => ['label' => 'Образовательная программа', 'required' => false, 'aliases' => ['образовательная программа', 'программа', 'education_program_name']],
            'year_start' => ['label' => 'Год начала', 'required' => true, 'aliases' => ['год начала', 'год', 'year_start', 'year']],
            'status' => ['label' => 'Статус', 'required' => false, 'aliases' => ['статус', 'status']],
            'description' => ['label' => 'Описание', 'required' => false, 'aliases' => ['описание', 'description']],
            'subject_id' => ['label' => 'ID дисциплины', 'required' => false, 'aliases' => ['subject_id', 'id дисциплины']],
            'subject_code' => ['label' => 'Код дисциплины', 'required' => false, 'aliases' => ['код дисциплины', 'subject_code']],
            'subject_name' => ['label' => 'Дисциплина', 'required' => false, 'aliases' => ['дисциплина', 'subject_name', 'subject']],
            'course' => ['label' => 'Курс', 'required' => false, 'aliases' => ['курс', 'course']],
            'semester' => ['label' => 'Семестр', 'required' => true, 'aliases' => ['семестр', 'semester']],
            'hours_total' => ['label' => 'Часы всего', 'required' => true, 'aliases' => ['часы всего', 'часы', 'всего часов', 'hours_total', 'total_hours']],
            'lecture_hours' => ['label' => 'Лекции', 'required' => false, 'aliases' => ['лекции', 'лекций', 'lecture_hours']],
            'practice_hours' => ['label' => 'Практика', 'required' => false, 'aliases' => ['практика', 'практических', 'practice_hours']],
            'laboratory_hours' => ['label' => 'Лабораторные', 'required' => false, 'aliases' => ['лабораторные', 'laboratory_hours']],
            'independent_hours' => ['label' => 'Самостоятельная работа', 'required' => false, 'aliases' => ['самостоятельная работа', 'срс', 'independent_hours']],
            'control_form' => ['label' => 'Форма контроля', 'required' => false, 'aliases' => ['форма контроля', 'контроль', 'control_form', 'control_type']],
            'sort_order' => ['label' => 'Порядок', 'required' => false, 'aliases' => ['порядок', 'sort_order', 'sequence']],
        ];
    }

    public function templateHeaders(): array
    {
        return ['Код учебного плана', 'Учебный план', 'Образовательная программа', 'Год начала', 'Статус', 'Дисциплина', 'Код дисциплины', 'Курс', 'Семестр', 'Часы всего', 'Лекции', 'Практика', 'Лабораторные', 'Самостоятельная работа', 'Форма контроля', 'Порядок'];
    }

    public function templateExample(): array
    {
        return ['УП-ФО-2026', 'Учебный план Фортепиано 2026', 'Фортепиано', '2026', 'draft', 'Сольфеджио', 'SOLF', '1', '1', '144', '34', '68', '0', '42', 'Экзамен', '10'];
    }

    public function prepare(array $data): array
    {
        $data['education_program_id'] = $this->resolveProgramId($data['education_program_id'] ?? null, $data['education_program_name'] ?? null);
        $data['subject_id'] = $this->resolveSubjectId($data['subject_id'] ?? null, $data['subject_code'] ?? null, $data['subject_name'] ?? null);
        $data['curriculum_code'] = $data['curriculum_code'] ?: $this->autoCodeService->curriculumCode($data['curriculum_name'] ?? null);
        $data['status'] = $data['status'] ?: 'draft';
        $data['sort_order'] = $data['sort_order'] ?: 0;

        // Пустая клетка в часах — это ноль, а не «не знаю»: план без лекций
        // существует, а план с неизвестными лекциями схему не проходит.
        foreach (['lecture_hours', 'practice_hours', 'laboratory_hours', 'independent_hours'] as $part) {
            $data[$part] = ($data[$part] ?? '') === '' ? 0 : $data[$part];
        }

        return $data;
    }

    public function rules(): array
    {
        return [
            'curriculum_code' => ['nullable', 'string', 'max:100'],
            'curriculum_name' => ['required', 'string', 'max:255'],
            'education_program_id' => ['required', 'integer', 'exists:education_programs,id'],
            'year_start' => ['required', 'integer', 'min:2000', 'max:2100'],
            'status' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'course' => ['nullable', 'integer', 'min:1', 'max:6'],
            'semester' => ['required', 'integer', 'min:1', 'max:12'],
            'hours_total' => ['required', 'integer', 'min:0', 'max:5000'],
            'lecture_hours' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'practice_hours' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'laboratory_hours' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'independent_hours' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'control_form' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    public function findExisting(array $data): ?Model
    {
        return Curriculum::where('code', $data['curriculum_code'] ?? null)->first();
    }

    public function import(array $data, string $mode): string
    {
        $this->assertSemesterMatchesCourse($data);

        $curriculum = Curriculum::where('code', $data['curriculum_code'])->first()
            ?: Curriculum::where('education_program_id', $data['education_program_id'])
                ->where('name', $data['curriculum_name'])
                ->where('year_start', $data['year_start'])
                ->first();

        if ($mode === self::MODE_UPDATE && ! $curriculum) {
            return $this->skipped(self::SKIP_NOT_FOUND);
        }

        $curriculumPayload = [
            'code' => $data['curriculum_code'],
            'education_program_id' => $data['education_program_id'],
            'name' => $data['curriculum_name'],
            'year_start' => $data['year_start'],
            'status' => $data['status'] ?: 'draft',
            'description' => $data['description'] ?? null,
        ];

        $curriculum = $curriculum ? tap($curriculum)->update($curriculumPayload) : Curriculum::create($curriculumPayload);

        $subject = CurriculumSubject::where('curriculum_id', $curriculum->id)
            ->where('subject_id', $data['subject_id'])
            ->where('semester', $data['semester'])
            ->first();

        if ($subject) {
            if ($mode === self::MODE_SKIP_DUPLICATES) {
                return $this->skipped(self::SKIP_DUPLICATE);
            }

            if ($mode === self::MODE_CREATE) {
                throw new RuntimeException('Дисциплина уже есть в этом плане на этот семестр.');
            }
        }

        $payload = [
            'curriculum_id' => $curriculum->id,
            'subject_id' => $data['subject_id'],
            'semester' => $data['semester'],
            'total_hours' => $data['hours_total'],
            'lecture_hours' => $data['lecture_hours'],
            'practice_hours' => $data['practice_hours'],
            'laboratory_hours' => $data['laboratory_hours'],
            'independent_hours' => $data['independent_hours'],
            'control_type' => $data['control_form'] ?? null,
            'sequence' => $data['sort_order'] ?? 0,
        ];

        if ($subject) {
            $subject->update($payload);

            return 'updated';
        }

        CurriculumSubject::create($payload);

        return 'created';
    }

    /**
     * Курс в `curriculum_subjects` не хранится — его задаёт семестр. Но раз
     * колонка в файле есть и человек её заполняет, она обязана проверяться:
     * молча проигнорированный столбец однажды разойдётся с семестром, и никто
     * этого не заметит.
     */
    private function assertSemesterMatchesCourse(array $data): void
    {
        $course = $data['course'] ?? null;

        if ($course === null || $course === '') {
            return;
        }

        $expected = [(int) $course * 2 - 1, (int) $course * 2];

        if (! in_array((int) $data['semester'], $expected, true)) {
            throw new RuntimeException(sprintf(
                'Семестр %d не относится к %d курсу: ожидались %d или %d.',
                (int) $data['semester'],
                (int) $course,
                $expected[0],
                $expected[1],
            ));
        }
    }
}
