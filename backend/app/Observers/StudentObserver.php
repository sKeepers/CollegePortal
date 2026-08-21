<?php

namespace App\Observers;

use App\Models\Student;
use App\Services\DigitalPassIssueService;
use App\Services\DigitalPassRevocationService;

class StudentObserver
{
    public function __construct(
        private readonly DigitalPassRevocationService $digitalPasses,
        private readonly DigitalPassIssueService $issue,
    ) {
    }

    /**
     * Пропуск выдаётся сразу при заведении карточки — решение владельца
     * 21.08.2026: он должен быть у каждого человека. Наблюдатель выбран
     * намеренно: студенты заводятся и поодиночке, и загрузкой, и зачислением из
     * приёмной комиссии, и через демонстрационный набор — перечислять эти пути
     * поимённо значит однажды забыть один из них.
     */
    public function created(Student $student): void
    {
        $this->issue->ensureForPerson($student->person_id);
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
