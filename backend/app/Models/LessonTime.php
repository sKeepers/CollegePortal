<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Строка сетки звонков.
 *
 * Время держится строкой «ЧЧ:ММ» и к дате не приводится намеренно: база отдаёт
 * `time` как «08:30:00», Carbon добавил бы к нему сегодняшний день, а расписание
 * сравнивает время строками. Обрезка до пяти знаков — всё, что здесь нужно.
 */
class LessonTime extends Model
{
    protected $fillable = ['lesson_number', 'starts_at', 'ends_at', 'label', 'is_active'];

    protected function casts(): array
    {
        return ['lesson_number' => 'integer', 'is_active' => 'boolean'];
    }

    public function startsAtShort(): string
    {
        return substr((string) $this->starts_at, 0, 5);
    }

    public function endsAtShort(): string
    {
        return substr((string) $this->ends_at, 0, 5);
    }
}
