<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Services\AuditLogService;
use App\Services\PersonDuplicateService;
use Illuminate\Http\Request;

/**
 * `HR-002`: кого портал нашёл по введённым данным — и ничего сверх этого.
 *
 * Кадровик заводит сотрудника, портал находит несколько похожих людей и просит
 * выбрать. Выбирать было не из чего: список в форме наполнялся реестром людей
 * под правом `people.view`, а его кадрам не выдают намеренно — в реестре лежат
 * ещё и студенты с абитуриентами.
 *
 * Здесь отдаётся не реестр, а **результат собственного ввода кадровика**: те
 * два-три человека, на которых он сам и вышел. Полей ровно столько, сколько
 * нужно, чтобы отличить одного от другого:
 *
 * - ФИО и дата рождения — то, чем люди различаются;
 * - кем человек уже является в портале — «студент», «сотрудник», «абитуриент»;
 * - **чем совпало** — СНИЛС читается иначе, чем общий телефон: первое почти
 *   наверняка тот же человек, второе бывает у супругов и у матери с дочерью.
 *
 * Чего здесь нет и не должно появиться: адреса, ИНН, СНИЛС, контактов
 * найденного человека и его карточек. Совпавшее значение кадровик и так знает —
 * он его только что ввёл, — а всё остальное к выбору отношения не имеет.
 *
 * Обращение пишется в журнал: это узкий, но всё-таки взгляд в общий реестр.
 */
class PersonMatchController extends Controller
{
    /** Кем человек уже является в портале. Подпись — счётчику связанных профилей. */
    private const ROLE_LABELS = [
        'students_count' => 'студент',
        'teachers_count' => 'преподаватель',
        'employees_count' => 'сотрудник',
        'applicants_count' => 'абитуриент',
        'graduates_count' => 'выпускник',
    ];

    public function __invoke(Request $request, PersonDuplicateService $duplicates): array
    {
        $data = $request->validate([
            'last_name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'snils' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $result = $duplicates->check($data);

        $matches = collect($result['matches'])
            ->map(fn (array $match): array => $this->present($match['person'], $match['matched_by']))
            ->values()
            ->all();

        AuditLogService::log('hr', 'person_match_lookup', ['type' => 'Person', 'id' => null], null, [
            'criteria' => $result['criteria'],
            'matches_count' => count($matches),
            'matched_person_ids' => array_column($matches, 'id'),
        ], $request);

        // Возвращаются только совпадения. По каким полям искали, кадровик знает —
        // он их сам и заполнил, а лишний перечень полей в ответе ни к чему.
        return ['data' => ['matches' => $matches]];
    }

    /** @param list<string> $matchedBy */
    private function present(Person $person, array $matchedBy): array
    {
        // `check` считает не все профили: кадровую запись он не грузит, а именно
        // она отвечает на вопрос «этот человек уже наш сотрудник?».
        $person->loadCount('employees');

        return [
            'id' => $person->id,
            'full_name' => collect([$person->last_name, $person->first_name, $person->middle_name])->filter()->implode(' '),
            'birth_date' => $person->birth_date?->toDateString(),
            'matched_by' => $matchedBy,
            'roles' => collect(self::ROLE_LABELS)
                ->filter(fn (string $label, string $countAttribute): bool => (int) ($person->{$countAttribute} ?? 0) > 0)
                ->values()
                ->all(),
        ];
    }
}
