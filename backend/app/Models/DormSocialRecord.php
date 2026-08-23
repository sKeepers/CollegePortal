<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Социальный паспорт и работа с трудными.
 *
 * Самые чувствительные данные во всём портале, тяжелее оценок и медицинских
 * справок. Отсюда форма хранения, а не только проверка права: **отдельная
 * таблица, а не колонки в `students`**. Карточка студента открыта восьми ролям,
 * и любое расширение выборки вынесло бы социальные сведения наружу; в отдельную
 * таблицу они физически не попадают.
 *
 * Право на неё выдано ровно одной роли — заместителю по воспитательной работе.
 * И **чтение пишется в аудит наравне с правкой**: сам факт просмотра здесь
 * событие, о котором должен остаться след.
 */
class DormSocialRecord extends Model
{
    public const CATEGORIES = [
        'orphan' => 'Сирота или без попечения',
        'guardianship' => 'Под опекой',
        'disability' => 'Инвалидность или ОВЗ',
        'low_income' => 'Малоимущая семья',
        'large_family' => 'Многодетная семья',
        'registered' => 'На профилактическом учёте',
        'difficult' => 'Работа с трудным',
        'other' => 'Иное',
    ];

    protected $fillable = [
        'student_id',
        'category',
        'details',
        'opened_on',
        'closed_on',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'opened_on' => 'date',
            'closed_on' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public static function categoryLabel(?string $category): string
    {
        return self::CATEGORIES[$category] ?? (string) $category;
    }
}
