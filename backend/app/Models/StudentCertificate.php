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
    protected $fillable = [
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
    public function isTransferred(): bool
    {
        return $this->course > 1;
    }
}
