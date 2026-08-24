<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Итоговая оценка студента по дисциплине за семестр.
 *
 * Не средний балл и не оценка за занятие: её **ставит преподаватель** по итогам семестра.
 * Из неё собирается приложение к диплому, ведомость и справка об обучении, и это
 * единственное место, где итог дисциплины существует как факт, а не как вычисление.
 */
class SemesterGrade extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id', 'subject_id', 'group_id', 'curriculum_subject_id',
        'academic_year', 'semester', 'control_type',
        'value', 'score', 'teacher_id', 'set_by', 'set_at', 'source', 'comment',
    ];

    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'score' => 'integer',
            'set_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function curriculumSubject(): BelongsTo
    {
        return $this->belongsTo(CurriculumSubject::class);
    }
}
