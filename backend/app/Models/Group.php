<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Group extends Model
{
    /**
     * Месяц, с которого счёт учебного года идёт по-новому.
     *
     * Первое сентября сюда не годится, хотя занятия начинаются с него. Группы
     * нового набора колледж заводит в августе — приказы о зачислении за четыре
     * года датированы 15-18 августа, — и с этого момента прошлогодние группы уже
     * считаются перешедшими на следующий курс. Замер 23.08.2026: у всех 68 групп
     * стенда хранившийся курс совпадал с вычисленным именно по августу, и не
     * совпал бы ни по одной группе, считай мы с сентября.
     *
     * Если в учебной части считают иначе — менять здесь, одно число.
     */
    public const ACADEMIC_YEAR_STARTS_IN_MONTH = 8;

    protected $fillable = [
        'name',
        'specialty',
        'education_program_id',
        'curriculum_id',
        'year_start',
        'curator_id',
    ];

    protected function casts(): array
    {
        return [
            'year_start' => 'integer',
        ];
    }

    /** Учебный год, идущий на указанную дату. */
    public static function academicYear(mixed $at = null): int
    {
        $moment = $at ? Carbon::parse($at) : Carbon::now();

        return $moment->month >= self::ACADEMIC_YEAR_STARTS_IN_MONTH ? $moment->year : $moment->year - 1;
    }

    /**
     * Курс не хранится, а считается из года набора.
     *
     * Хранимый курс пришлось бы каждое лето сдвигать руками по всем группам — а
     * пропущенный сдвиг виден не сразу и не весь: расписание, ведомости и
     * учебные планы разъезжаются молча.
     *
     * Группа будущего набора даёт ноль или меньше — показываем первый курс:
     * она заведена заранее, и первокурсниками её студенты станут, а не были.
     */
    public function getCourseAttribute(): ?int
    {
        if ($this->year_start === null) {
            return null;
        }

        return max(1, self::academicYear() - (int) $this->year_start + 1);
    }

    /** Отбор по курсу — тот же счёт, только со стороны запроса. */
    public function scopeOnCourse(Builder $query, int $course): Builder
    {
        return $query->where('year_start', self::academicYear() - $course + 1);
    }

    public function curator(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'curator_id');
    }

    public function educationProgram(): BelongsTo
    {
        return $this->belongsTo(EducationProgram::class);
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function scheduleLessons(): HasMany
    {
        return $this->hasMany(ScheduleLesson::class);
    }

    public function teachingLoadItems(): HasMany
    {
        return $this->hasMany(TeachingLoadItem::class);
    }
}
