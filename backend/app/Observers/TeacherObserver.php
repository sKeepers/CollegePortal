<?php

namespace App\Observers;

use App\Models\Teacher;
use App\Services\DigitalPassRevocationService;

class TeacherObserver
{
    public function __construct(private readonly DigitalPassRevocationService $digitalPasses)
    {
    }

    public function updated(Teacher $teacher): void
    {
        if ($teacher->wasChanged('is_active') && ! $teacher->is_active) {
            $this->digitalPasses->revokeForTeacher($teacher);
        }
    }

    public function deleting(Teacher $teacher): void
    {
        $this->digitalPasses->revokeForTeacher($teacher);
    }
}
