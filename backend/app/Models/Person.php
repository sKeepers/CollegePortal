<?php

namespace App\Models;

use App\Models\Admissions\Applicant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Person extends Model
{
    // Помеченный на удаление человек уходит в корзину и перестаёт попадать в
    // раздел «Люди», в поиск и в проверку дублей — но остаётся возвратным,
    // пока администратор не вычистит корзину.
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'last_name',
        'first_name',
        'middle_name',
        'birth_date',
        'gender',
        'citizenship',
        'place_birth',
        'phone',
        'email',
        'address',
        'photo_path',
        'snils',
        'snils_hash',
        'inn',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
        ];
    }

    public function students(): HasMany { return $this->hasMany(Student::class); }
    public function teachers(): HasMany { return $this->hasMany(Teacher::class); }
    public function applicants(): HasMany { return $this->hasMany(Applicant::class); }
    public function admissionIdentityDocuments(): HasMany { return $this->hasMany(\App\Models\Admissions\IdentityDocument::class); }
    public function admissionEducationDocuments(): HasMany { return $this->hasMany(\App\Models\Admissions\EducationDocument::class); }
    public function applicantApplications(): HasMany
    {
        return $this->hasMany(ApplicantApplication::class)
            ->where('record_type', ApplicantApplication::RECORD_TYPE_LEGACY);
    }
    public function graduates(): HasMany { return $this->hasMany(Graduate::class); }
    public function users(): HasMany { return $this->hasMany(User::class); }
    public function digitalIdentities(): HasMany { return $this->hasMany(DigitalIdentity::class); }
    public function rfidCards(): HasMany { return $this->hasMany(RfidCard::class); }

    /**
     * Последняя выданная карта из тех, что на руках.
     *
     * **Не единственная.** Раньше здесь стояло «у человека она одна», и это
     * было верно ровно до 28.08.2026: владелец сказал, что на человека бывает
     * записано несколько карт, и запрет на вторую снят в `RfidCardService`.
     * Отношение осталось для мест, где нужна одна строка на человека — список
     * выдачи, карточка человека, — и берёт самую свежую по `issued_at`.
     *
     * Сколько их всего, отвечает `rfidCardsOnHands()`: полагаться на это
     * отношение как на «все карты» нельзя, оно вернёт одну и промолчит об
     * остальных.
     */
    public function currentRfidCard(): HasOne
    {
        return $this->hasOne(RfidCard::class)->ofMany(
            ['issued_at' => 'MAX'],
            fn ($query) => $query->whereIn('status', [RfidCard::STATUS_ISSUED, RfidCard::STATUS_BLOCKED]),
        );
    }
    /** Все карты, которые сейчас на руках: их бывает несколько. */
    public function rfidCardsOnHands(): HasMany
    {
        return $this->hasMany(RfidCard::class)
            ->whereIn('status', [RfidCard::STATUS_ISSUED, RfidCard::STATUS_BLOCKED])
            ->orderByDesc('issued_at');
    }

    public function employees(): HasMany { return $this->hasMany(Employee::class); }

    public function primaryStudent(): HasOne { return $this->hasOne(Student::class)->latestOfMany(); }
    public function primaryTeacher(): HasOne { return $this->hasOne(Teacher::class)->latestOfMany(); }
    public function primaryEmployee(): HasOne { return $this->hasOne(Employee::class)->latestOfMany(); }
}
