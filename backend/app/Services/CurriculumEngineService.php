<?php

namespace App\Services;

use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Group;
use Illuminate\Support\Collection;

class CurriculumEngineService
{
    /** @return Collection<int, CurriculumSubject> */
    public function subjectsForGroup(Group $group, ?int $semester = null): Collection
    {
        if (! $group->curriculum_id) {
            return collect();
        }

        return CurriculumSubject::query()
            ->with(['subject', 'controlType'])
            ->where('curriculum_id', $group->curriculum_id)
            ->when($semester, fn ($query, int $semester) => $query->where('semester', $semester))
            ->orderBy('semester')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
    }

    public function summary(Curriculum $curriculum): array
    {
        $subjects = $curriculum->subjects()->with('controlType')->get();
        $controlCounts = $subjects
            ->groupBy(fn (CurriculumSubject $subject) => $subject->control_type ?: $subject->controlType?->code ?: 'none')
            ->map->count()
            ->all();

        return [
            'subjects_count' => $subjects->count(),
            'total_hours' => $subjects->sum('total_hours'),
            'lecture_hours' => $subjects->sum('lecture_hours'),
            'practice_hours' => $subjects->sum('practice_hours'),
            'laboratory_hours' => $subjects->sum('laboratory_hours'),
            'independent_hours' => $subjects->sum('independent_hours'),
            'exams_count' => $controlCounts['exam'] ?? 0,
            'credits_count' => $controlCounts['credit'] ?? 0,
            'differentiated_credits_count' => $controlCounts['differentiated_credit'] ?? 0,
            'practices_count' => $controlCounts['practice'] ?? 0,
            'courseworks_count' => ($controlCounts['coursework'] ?? 0) + ($controlCounts['project'] ?? 0),
            'gia_count' => $controlCounts['gia'] ?? 0,
            'control_counts' => $controlCounts,
        ];
    }

    public function semesters(Curriculum $curriculum): array
    {
        return $curriculum->subjects()
            ->with(['subject', 'controlType'])
            ->get()
            ->groupBy('semester')
            ->sortKeys()
            ->map(fn (Collection $subjects, int|string $semester): array => [
                'semester' => (int) $semester,
                'subjects_count' => $subjects->count(),
                'total_hours' => $subjects->sum('total_hours'),
                'subjects' => $subjects->values(),
            ])
            ->values()
            ->all();
    }
}
