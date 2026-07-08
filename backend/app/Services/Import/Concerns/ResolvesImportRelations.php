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

    protected function resolveClassroomId($id, ?string $number, ?string $building): ?int
    {
        if ($id) { return (int) $id; }
        if (!$number) { return null; }
        $query = Classroom::where('number', $number);
        if ($building) { $query->where('building', $building); }
        return $query->value('id');
    }
}
