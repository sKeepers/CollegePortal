<?php

namespace App\Services\Import;

use App\Models\EducationProgram;
use App\Models\Specialty;
use Illuminate\Database\Eloquent\Model;

/**
 * Загрузка образовательных программ.
 *
 * Специальность в файле указывается кодом, а не идентификатором: код
 * («53.02.03») человек видит в документах и наберёт в Excel, идентификатор
 * строки — нет. Названия для этого не годятся: у одной специальности бывает
 * несколько программ, а одно и то же название специальности встречается в
 * разных формах обучения.
 */
class EducationProgramImportHandler extends AbstractImportHandler
{
    public function type(): string { return 'education_programs'; }
    public function label(): string { return 'Образовательные программы'; }
    public function modelClass(): string { return EducationProgram::class; }
    public function keyFields(): array { return ['name']; }

    public function fields(): array
    {
        return [
            'specialty_code' => ['label' => 'Код специальности', 'required' => true, 'aliases' => ['код специальности', 'специальность', 'specialty_code']],
            'specialty_id' => ['label' => 'ID специальности', 'required' => false, 'aliases' => ['specialty_id']],
            'name' => ['label' => 'Программа', 'required' => true, 'aliases' => ['программа', 'образовательная программа', 'название', 'name']],
            'year_start' => ['label' => 'Год начала', 'required' => true, 'aliases' => ['год начала', 'год набора', 'year_start']],
            'study_form' => ['label' => 'Форма обучения', 'required' => true, 'aliases' => ['форма обучения', 'study_form']],
            'study_years' => ['label' => 'Срок обучения', 'required' => false, 'aliases' => ['срок обучения', 'study_years']],
            'is_active' => ['label' => 'Активна', 'required' => false, 'aliases' => ['активна', 'активен', 'is_active']],
            'description' => ['label' => 'Описание', 'required' => false, 'aliases' => ['описание', 'description']],
        ];
    }

    public function templateHeaders(): array
    {
        return ['Код специальности', 'Программа', 'Год начала', 'Форма обучения', 'Срок обучения', 'Активна', 'Описание'];
    }

    public function templateExample(): array
    {
        return ['53.02.03', 'Фортепиано', '2026', 'Очная', '3.8', 'да', ''];
    }

    public function prepare(array $data): array
    {
        $data['specialty_id'] = $this->resolveSpecialtyId($data['specialty_id'] ?? null, $data['specialty_code'] ?? null);
        $data['is_active'] = $this->booleanValue($data['is_active'] ?? true);

        return $data;
    }

    public function rules(): array
    {
        return [
            'specialty_code' => ['nullable', 'string', 'max:50'],
            'specialty_id' => ['nullable', 'integer', 'exists:specialties,id'],
            'name' => ['required', 'string', 'max:255'],
            'year_start' => ['required', 'integer', 'min:2000', 'max:2100'],
            'study_form' => ['required', 'string', 'max:100'],
            'study_years' => ['nullable', 'numeric', 'min:0.5', 'max:10'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string'],
        ];
    }

    /**
     * Ошибка ставится на колонку кода, а не на идентификатор: в файле у человека
     * колонка «Код специальности», и сообщение про `specialty_id` указывало бы
     * на то, чего он не видит.
     */
    public function businessValidationErrors(array $data): array
    {
        if (! empty($data['specialty_id'])) {
            return [];
        }

        $code = trim((string) ($data['specialty_code'] ?? ''));

        return ['specialty_code' => [$code === ''
            ? 'Не указан код специальности.'
            : "Специальность с кодом {$code} не найдена.",
        ]];
    }

    /**
     * Программа опознаётся по всей четвёрке: у одной специальности бывают
     * «Фортепиано» очное и заочное и наборы разных лет, и по одному названию
     * обновилась бы не та строка.
     */
    public function findExisting(array $data): ?Model
    {
        foreach (['specialty_id', 'name', 'year_start', 'study_form'] as $field) {
            if (empty($data[$field])) {
                return null;
            }
        }

        return EducationProgram::query()
            ->where('specialty_id', $data['specialty_id'])
            ->where('name', $data['name'])
            ->where('year_start', $data['year_start'])
            ->where('study_form', $data['study_form'])
            ->first();
    }

    protected function virtualFields(): array
    {
        // Код специальности — способ её назвать, а не колонка программы.
        return ['specialty_code'];
    }

    private function resolveSpecialtyId($id, ?string $code): ?int
    {
        if ($id) {
            return (int) $id;
        }

        $code = trim((string) $code);

        return $code === '' ? null : Specialty::where('code', $code)->value('id');
    }
}
