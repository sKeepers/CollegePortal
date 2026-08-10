<?php

namespace App\Services\Import\Concerns;

use App\Models\Classroom;
use App\Models\EducationProgram;
use App\Models\Group;
use App\Models\Subject;
use App\Models\Teacher;

trait ResolvesImportRelations
{
    protected function resolveGroupId($id, ?string $name): ?int
    {
        if ($id) { return (int) $id; }
        return $name ? Group::where('name', $name)->value('id') : null;
    }

    protected function resolveProgramId($id, ?string $name): ?int
    {
        if ($id) { return (int) $id; }
        return $name ? EducationProgram::where('name', $name)->value('id') : null;
    }

    protected function resolveSubjectId($id, ?string $code, ?string $name): ?int
    {
        if ($id) { return (int) $id; }
        if ($code) { return Subject::where('code', $code)->value('id'); }
        return $name ? Subject::where('name', $name)->value('id') : null;
    }

    protected function resolveTeacherId($id, ?string $name): ?int
    {
        if ($id) { return (int) $id; }
        $name = trim((string) $name);
        if ($name === '') { return null; }
        return Teacher::query()
            ->whereRaw("trim(concat_ws(' ', last_name, first_name, middle_name)) = ?", [$name])
            ->orWhereRaw("trim(concat_ws(' ', last_name, first_name)) = ?", [$name])
            ->value('id');
    }

    /**
     * Список преподавателей одной колонкой: «Фамилия Имя Отчество | Фамилия Имя».
     * Числовое значение считается идентификатором — файл с машинными колонками
     * грузится в ту же колонку и не требует второго шаблона.
     *
     * Имя, под которым нашлось несколько преподавателей, не связывается ни с
     * кем: тихая привязка к первому попавшемуся однофамильцу — это неверные
     * данные, о которых никто не узнает. Вызывающий превращает `unresolved`
     * в ошибку строки.
     *
     * @return array{ids: array<int, int>, unresolved: array<int, string>}
     */
    protected function resolveTeacherIdList(?string $value): array
    {
        $ids = [];
        $unresolved = [];

        foreach (preg_split('/\s*[|,]\s*/u', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (ctype_digit($part)) {
                $ids[] = (int) $part;
                continue;
            }

            $matches = Teacher::query()
                ->whereRaw("trim(concat_ws(' ', last_name, first_name, middle_name)) = ?", [$part])
                ->orWhereRaw("trim(concat_ws(' ', last_name, first_name)) = ?", [$part])
                ->pluck('id');

            if ($matches->count() === 1) {
                $ids[] = (int) $matches->first();
                continue;
            }

            $unresolved[] = $part;
        }

        return ['ids' => array_values(array_unique($ids)), 'unresolved' => $unresolved];
    }

    protected function resolveClassroomId($id, ?string $number, ?string $building): ?int
    {
        if ($id) { return (int) $id; }
        if (!$number) { return null; }
        $query = Classroom::where('number', $number);
        if ($building) { $query->where('building', $building); }
        return $query->value('id');
    }
}
