<?php

namespace App\Observers;

use App\Models\Employee;
use App\Services\DigitalPassIssueService;

/**
 * Пропуск сотруднику выдаётся сразу при заведении карточки.
 *
 * Отзыва здесь нет намеренно: у сотрудника увольнение оформляется не флагом на
 * карточке, а периодом состояния, и отзывать пропуск на удалении карточки —
 * задача другой области. Наблюдатель заведён ради одной обязанности: чтобы
 * человек не оставался без пропуска, каким бы путём его ни завели.
 */
class EmployeeObserver
{
    public function __construct(private readonly DigitalPassIssueService $issue)
    {
    }

    public function created(Employee $employee): void
    {
        $this->issue->ensureForPerson($employee->person_id);
    }

    /** Человек может привязаться позже создания карточки — см. StudentObserver. */
    public function updated(Employee $employee): void
    {
        if ($employee->wasChanged('person_id')) {
            $this->issue->ensureForPerson($employee->person_id);
        }
    }
}
