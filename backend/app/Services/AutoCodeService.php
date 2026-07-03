<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AutoCodeService
{
    public function next(string $modelClass, string $prefix, string $field = 'code'): string
    {
        $prefix = mb_strtoupper(trim($prefix));
        $prefix = preg_replace('/[^A-ZА-Я0-9]+/u', '-', $prefix) ?: 'CP';
        $prefix = trim($prefix, '-');

        /** @var class-string<Model> $modelClass */
        $maxId = (int) $modelClass::query()->max('id');

        do {
            $candidate = sprintf('%s-%03d', $prefix, ++$maxId);
        } while ($modelClass::query()->where($field, $candidate)->exists());

        return $candidate;
    }

    public function subjectCode(?string $name = null): string
    {
        return $this->next(\App\Models\Subject::class, $this->prefixFromText($name, 'DISC'));
    }

    public function specialtyCode(?string $name = null): string
    {
        return $this->next(\App\Models\Specialty::class, $this->prefixFromText($name, 'SPEC'));
    }

    public function curriculumCode(?string $name = null): string
    {
        return $this->next(\App\Models\Curriculum::class, $this->prefixFromText($name, 'PLAN'));
    }

    public function groupName(?string $specialty = null, ?int $yearStart = null, ?int $course = null): string
    {
        $base = $this->prefixFromText($specialty, 'GR');
        $suffix = $yearStart ? mb_substr((string) $yearStart, -2) : now()->format('y');
        $course = $course ?: 1;
        $prefix = sprintf('%s-%d%s', $base, $course, $suffix);

        return $this->next(\App\Models\Group::class, $prefix, 'name');
    }

    private function prefixFromText(?string $text, string $fallback): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return $fallback;
        }

        $words = preg_split('/\s+/u', $text) ?: [];
        $letters = [];
        foreach ($words as $word) {
            $letters[] = mb_substr($word, 0, 1);
            if (count($letters) >= 4) {
                break;
            }
        }

        $prefix = mb_strtoupper(implode('', $letters));
        $prefix = preg_replace('/[^A-ZА-Я0-9]/u', '', $prefix);

        return $prefix ?: Str::upper(Str::limit(Str::slug($text, ''), 6, '')) ?: $fallback;
    }
}
