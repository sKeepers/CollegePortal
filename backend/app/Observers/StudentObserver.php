<?php

namespace App\Observers;

use App\Models\Student;
use App\Services\DigitalPassIssueService;
use App\Services\DigitalPassRevocationService;
use App\Services\PersonalFileNumberService;

class StudentObserver
{
    public function __construct(
        private readonly DigitalPassRevocationService $digitalPasses,
        private readonly DigitalPassIssueService $issue,
        private readonly PersonalFileNumberService $personalFile,
    ) {
    }

    /**
     * Буква личного дела проставляется до вставки, номер — если его не дали.
     *
     * Здесь, а не в контроллере: карточку заводят и руками, и загрузкой, и
     * зачислением из приёмной комиссии. Номер, пришедший из файла, не трогается:
     * там он настоящий, из алфавитной книги колледжа.
     */
    public function creating(Student $student): void
    {
        $this->personalFile->assign($student);
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

        // Человек может привязаться к карточке позже её создания — так делает
        // загрузка контингента: сначала пишет строку, потом ищет или заводит
        // человека. На заведении пропуск выдавать было не к кому, и первые
        // десять зачисленных 22.08.2026 остались без пропусков. Ловим момент,
        // когда ссылка появилась.
        if ($student->wasChanged('person_id')) {
            $this->issue->ensureForPerson($student->person_id);
        }
    }

    public function deleting(Student $student): void
    {
        $this->digitalPasses->revokeForStudent($student);
    }
}
