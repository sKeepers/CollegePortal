<?php

namespace App\Services\People;

use App\Models\Admissions\Applicant;
use App\Models\Admissions\EducationDocument;
use App\Models\Admissions\IdentityDocument;
use App\Models\ApplicantApplication;
use App\Models\DigitalIdentity;
use App\Models\Employee;
use App\Models\Graduate;
use App\Models\Person;
use App\Models\RfidCard;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Слияние двух карточек одного человека.
 *
 * В разделе «Люди» дубли появляются законным путём: человека заводит загрузка
 * контингента по ФИО, а потом кадры заводят его же по СНИЛС — и в портале два
 * человека вместо одного. До 23.08.2026 свести их можно было только запросом к
 * базе, и владелец просил об этом дважды.
 *
 * Одна карточка остаётся, вторая исчезает, а всё, что за ней стояло, переезжает
 * на оставшуюся. Обратного хода нет, поэтому сначала показывается разбор
 * (`plan`), и только потом выполняется слияние.
 */
class PersonMergeService
{
    /**
     * Что переезжает на оставшуюся карточку.
     *
     * Перечислено моделями, а не связями Person, намеренно: связь
     * `applicantApplications` сужена до `record_type = legacy`, и заявления
     * нового вида остались бы висеть на исчезнувшем человеке. Внешний ключ
     * такого сужения не знает.
     *
     * @var array<class-string, string>
     */
    private const MOVES = [
        Student::class => 'Карточка студента',
        Teacher::class => 'Карточка преподавателя',
        Employee::class => 'Карточка сотрудника',
        Applicant::class => 'Карточка абитуриента',
        ApplicantApplication::class => 'Заявление абитуриента',
        IdentityDocument::class => 'Документ, удостоверяющий личность',
        EducationDocument::class => 'Документ об образовании',
        Graduate::class => 'Запись выпускника',
        User::class => 'Учётная запись',
        DigitalIdentity::class => 'Электронный пропуск',
        RfidCard::class => 'RFID-карта',
    ];

    /**
     * Карточки, которых у человека не бывает по две.
     *
     * Связь к профилю — `hasOne`, и вторая карточка молча перекрывает первую:
     * кабинет и журнал у неё пустые. На этом уже стояли две карточки
     * преподавателя у `teacher@local`. Поэтому слияние, которое дало бы человеку
     * два студенческих или два преподавательских профиля, не выполняется, а
     * отказывает и называет причину.
     *
     * @var array<class-string, string>
     */
    private const ONE_PER_PERSON = [
        Student::class => 'студента',
        Teacher::class => 'преподавателя',
        Employee::class => 'сотрудника',
    ];

    /**
     * Общие поля. Пустое у оставшейся карточки заполняется из исчезающей —
     * непустое не трогается никогда: то, что человек ввёл руками, слияние
     * переписывать не вправе.
     *
     * @var array<int, string>
     */
    private const SHARED_FIELDS = ['last_name', 'first_name', 'middle_name', 'birth_date', 'phone', 'email', 'snils'];

    /**
     * Разбор перед слиянием: что переедет, что дозаполнится и что мешает.
     *
     * @return array{moves: array<int, array{code: string, label: string, count: int}>, fills: array<int, array{field: string, label: string, value: string}>, blockers: array<int, string>}
     */
    public function plan(Person $survivor, Person $absorbed): array
    {
        return [
            'moves' => $this->moves($absorbed),
            'fills' => $this->fills($survivor, $absorbed),
            'blockers' => $this->blockers($survivor, $absorbed),
        ];
    }

    /**
     * Выполняет слияние. Отказ — исключением с русским текстом: он доходит до
     * человека, а не остаётся в журнале.
     *
     * @return array{moved: array<int, array{code: string, label: string, count: int}>, filled: array<int, string>}
     */
    public function merge(Person $survivor, Person $absorbed): array
    {
        // Разбор повторяется внутри транзакции: между показом и нажатием могло
        // пройти сколько угодно времени, и за это время у обоих могла появиться
        // карточка одного вида.
        return DB::transaction(function () use ($survivor, $absorbed): array {
            $blockers = $this->blockers($survivor, $absorbed);

            if ($blockers !== []) {
                throw new RuntimeException(implode(' ', $blockers));
            }

            $moved = $this->moves($absorbed);

            foreach (array_keys(self::MOVES) as $model) {
                $model::query()->where('person_id', $absorbed->id)->update(['person_id' => $survivor->id]);
            }

            $filled = [];

            foreach ($this->fills($survivor, $absorbed) as $fill) {
                $survivor->{$fill['field']} = $absorbed->{$fill['field']};
                $filled[] = $fill['label'];
            }

            if ($filled !== []) {
                $survivor->save();
            }

            // Карточка удаляется совсем, а не помечается: помеченная осталась бы
            // в корзине пустой оболочкой без единой связи, и восстанавливать в
            // ней было бы нечего.
            $absorbed->forceDelete();

            return ['moved' => $moved, 'filled' => $filled];
        });
    }

    /** @return array<int, array{code: string, label: string, count: int}> */
    private function moves(Person $absorbed): array
    {
        $moves = [];

        foreach (self::MOVES as $model => $label) {
            $count = $model::query()->where('person_id', $absorbed->id)->count();

            if ($count > 0) {
                $moves[] = ['code' => class_basename($model), 'label' => $label, 'count' => $count];
            }
        }

        return $moves;
    }

    /** @return array<int, array{field: string, label: string, value: string}> */
    private function fills(Person $survivor, Person $absorbed): array
    {
        $labels = [
            'last_name' => 'Фамилия',
            'first_name' => 'Имя',
            'middle_name' => 'Отчество',
            'birth_date' => 'Дата рождения',
            'phone' => 'Телефон',
            'email' => 'Email',
            'snils' => 'СНИЛС',
        ];

        $fills = [];

        foreach (self::SHARED_FIELDS as $field) {
            $mine = $survivor->{$field};
            $theirs = $absorbed->{$field};

            if (blank($mine) && filled($theirs)) {
                $fills[] = [
                    'field' => $field,
                    'label' => $labels[$field],
                    'value' => $theirs instanceof \DateTimeInterface ? $theirs->format('d.m.Y') : (string) $theirs,
                ];
            }
        }

        return $fills;
    }

    /** @return array<int, string> */
    private function blockers(Person $survivor, Person $absorbed): array
    {
        $blockers = [];

        if ($survivor->id === $absorbed->id) {
            return ['Это одна и та же карточка.'];
        }

        foreach (self::ONE_PER_PERSON as $model => $name) {
            $mine = $model::query()->where('person_id', $survivor->id)->count();
            $theirs = $model::query()->where('person_id', $absorbed->id)->count();

            if ($mine > 0 && $theirs > 0) {
                $blockers[] = "У обеих карточек есть карточка {$name}: после слияния их стало бы две, а вторая молча перекрывает первую. Сначала разберитесь, какая из них верная.";
            }
        }

        // Разные СНИЛС — сильный признак, что это разные люди: по нему человек
        // находится и к нему привязаны документы. Молча оставить один из двух
        // нельзя, поэтому не сливаем вовсе.
        if (filled($survivor->snils) && filled($absorbed->snils) && $survivor->snils !== $absorbed->snils) {
            $blockers[] = 'У карточек разные СНИЛС. Скорее всего это разные люди; если нет — сначала исправьте неверный.';
        }

        return $blockers;
    }
}
