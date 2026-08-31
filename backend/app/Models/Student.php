<?php

namespace App\Models;

use App\Support\Students\FundingForm;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    // Удаление карточки не окончательное: она уходит в корзину, откуда её
    // возвращает или вычищает администратор.
    use SoftDeletes;

    protected $fillable = [
        'person_id',
        'user_id',
        'group_id',
        'course',
        'last_name',
        'first_name',
        'middle_name',
        'birth_date',
        'phone',
        'email',
        'snils',
        'address',
        'passport_series',
        'passport_number',
        'passport_issue_date',
        'passport_issued_by',
        'passport_department_code',
        'photo_path',
        'status',
        'is_resident',
        'enrollment_date',
        'enrollment_order_number',
        'enrollment_order_date',
        // Номер личного дела, он же номер зачётной книжки, он же номер в
        // алфавитном классификаторе бумажных списков — это один номер.
        'personal_file_number',
        // Буква закрепляется за делом при заведении и при смене фамилии не меняется.
        'personal_file_letter',
        'education_form',
        'funding_form',
        'archived_at',
    ];

    /**
     * Форма финансирования приводится к одному написанию при любой записи.
     *
     * Колледж говорит «хозрасчёт», в базе лежит «Договор» — 63 студента на
     * 31.08.2026. Подпись на экране переписана на привычное слово, и без этого
     * правила первый же человек, набравший «Хозрасчёт» в поле или приславший
     * файл со своим словом, завёл бы **второе значение для того же смысла**:
     * отбор «кто на договоре» перестал бы находить половину.
     *
     * Правило стоит на модели, а не в контроллере, потому что путей записи у
     * студента несколько: форма, массовое действие, загрузка CSV, импорт из
     * файла и консольные команды. Так же закрыт номер бланка диплома.
     */
    protected function fundingForm(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => FundingForm::store($value),
        );
    }

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'enrollment_date' => 'date',
            'passport_issue_date' => 'date',
            'enrollment_order_date' => 'date',
            'course' => 'integer',
            'is_resident' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }
}
