<?php

namespace App\Models;

use App\Services\Graduation\Exceptions\StrictReportingRecordIsNeverDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Выданная студенту справка.
 *
 * Поля с данными студента — снимок на момент выдачи, а не окно в карточку.
 * Студента переведут на курс выше, группу переименуют, специальность уточнят, —
 * выданная справка обязана остаться такой, какой её подписал директор.
 */
class StudentCertificate extends Model
{
    /** Выдана порталом: снимок полон, за строкой стоит студент. */
    public const SOURCE_PORTAL = 'portal';

    /**
     * Перенесена из бумажного реестра колледжа.
     *
     * Такая строка знает только то, что было на бумаге: номер, ФИО, дату
     * рождения, приказ о зачислении. Даты выдачи в реестре нет вовсе, курса и
     * сроков обучения тоже, а у 89 строк из 591 нет и студента — это выбывшие.
     * Подставлять сюда сегодняшнюю карточку нельзя: на бумаге стоял тот курс,
     * что был тогда.
     */
    public const SOURCE_PAPER = 'paper';

    protected $fillable = [
        'source',
        'student_id',
        'number',
        'issued_on',
        'issued_by_user_id',
        'full_name',
        'birth_date',
        'course',
        'specialty',
        'study_form',
        'enrollment_order_number',
        'enrollment_order_date',
        'transfer_order_number',
        'transfer_order_date',
        'study_start',
        'study_end',
        'received_on',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'course' => 'integer',
            'issued_on' => 'date',
            'birth_date' => 'date',
            'enrollment_order_date' => 'date',
            'transfer_order_date' => 'date',
            'study_start' => 'date',
            'study_end' => 'date',
            'received_on' => 'date',
        ];
    }

    /**
     * Реестр, из которого можно убрать строку, реестром не является.
     *
     * Пропуск в нумерации виден сразу — спрятанная строка нет. Маршрута на
     * удаление тоже нет вовсе; запрет здесь на случай, если он появится.
     */
    public function delete(): bool
    {
        throw new StrictReportingRecordIsNeverDeleted(
            'Выданная справка из реестра не удаляется: номер уже на бумаге у студента.',
        );
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    /** Нужен ли в бланке приказ о переводе. У первого курса переводить неоткуда. */
    /** Строка бумажного реестра: у неё нет снимка и может не быть студента. */
    public function isFromPaper(): bool
    {
        return $this->source === self::SOURCE_PAPER;
    }

    public function isTransferred(): bool
    {
        return $this->course > 1;
    }
}
