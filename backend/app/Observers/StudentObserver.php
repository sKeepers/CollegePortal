<?php

namespace App\Observers;

use App\Models\Student;
use App\Services\DigitalPassRevocationService;

class StudentObserver
{
    public function __construct(private readonly DigitalPassRevocationService $digitalPasses)
    {
    }

    public function updated(Student $student): void
    {
        if ($student->wasChanged('status') && $student->status !== 'active') {
            $this->digitalPasses->revokeForStudent($student);
        }
    }

    public function deleting(Student $student): void
    {
        $this->digitalPasses->revokeForStudent($student);
    }
}
