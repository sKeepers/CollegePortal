<?php

namespace App\Services\Import;

use App\Models\Specialty;
use Illuminate\Database\Eloquent\Model;

/**
 * Загрузка специальностей.
 *
 * Обработчика у специальностей не было вовсе: реестр грузился только со своего
 * экрана собственным CSV-импортом, а «Универсальный импорт» о нём не знал — ни
 * шаблона, ни предпросмотра, ни разбора ошибок по строкам. Заодно это даёт
 * выгрузке человеческие заголовки: до 10.08.2026 специальности и программы
 * оставались последними двумя реестрами, отдававшими машинные имена полей.
 */
class SpecialtyImportHandler extends AbstractImportHandler
{
    public function type(): string { return 'specialties'; }
    public function label(): string { return 'Специальности'; }
    public function modelClass(): string { return Specialty::class; }
    public function keyFields(): array { return ['code']; }

    public function fields(): array
    {
        return [
            'code' => ['label' => 'Код', 'required' => true, 'aliases' => ['код', 'код специальности', 'code']],
            'name' => ['label' => 'Название', 'required' => true, 'aliases' => ['название', 'специальность', 'name']],
            'education_level' => ['label' => 'Уровень образования', 'required' => true, 'aliases' => ['уровень образования', 'уровень', 'education_level']],
            'qualification' => ['label' => 'Квалификация', 'required' => false, 'aliases' => ['квалификация', 'qualification']],
            'normative_study_years' => ['label' => 'Нормативный срок', 'required' => false, 'aliases' => ['нормативный срок', 'срок обучения', 'normative_study_years']],
            'description' => ['label' => 'Описание', 'required' => false, 'aliases' => ['описание', 'description']],
        ];
    }

    public function templateHeaders(): array
    {
        return ['Код', 'Название', 'Уровень образования', 'Квалификация', 'Нормативный срок', 'Описание'];
    }

    public function templateExample(): array
    {
        return [
            '53.02.03',
            'Инструментальное исполнительство',
            'Среднее профессиональное образование - программа подготовки специалистов среднего звена',
            'Артист, преподаватель, концертмейстер',
            '3.8',
            '',
        ];
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'education_level' => ['required', 'string', 'max:255'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'normative_study_years' => ['nullable', 'numeric', 'min:0.5', 'max:10'],
            'description' => ['nullable', 'string'],
        ];
    }

    public function findExisting(array $data): ?Model
    {
        return ! empty($data['code']) ? Specialty::where('code', $data['code'])->first() : null;
    }
}
