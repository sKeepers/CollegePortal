<?php

namespace App\Services;

use App\Models\DormPayment;
use App\Models\DormPlacement;
use App\Models\Student;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Оплата проживания.
 *
 * Оплата считается не помесячно, а «оплачено по такое-то число» — так её ведёт
 * владелец, и так же её понимает комендант.
 *
 * В таблицу пишут двое, поэтому правило спора задано сразу: **строка из 1С
 * побеждает ручную отметку**, а ручная помечается замещённой и остаётся в
 * истории. Ручная отметка при этом ничего из 1С не замещает: обмен — источник
 * денег, а комендант отмечает по бумажке, пока обмена нет.
 */
class DormPaymentService
{
    public function record(
        Student $student,
        CarbonInterface|string $paidThrough,
        ?float $amount = null,
        CarbonInterface|string|null $paidAt = null,
        string $origin = DormPayment::ORIGIN_MANUAL,
        ?string $externalId = null,
        ?string $note = null,
    ): DormPayment {
        if (! in_array($origin, DormPayment::ORIGINS, true)) {
            throw ValidationException::withMessages(['origin' => 'Неизвестное происхождение отметки об оплате.']);
        }

        return DB::transaction(function () use ($student, $paidThrough, $amount, $paidAt, $origin, $externalId, $note): DormPayment {
            $payment = DormPayment::create([
                'student_id' => $student->id,
                'paid_through' => $paidThrough,
                'amount' => $amount,
                'paid_at' => $paidAt,
                'origin' => $origin,
                'external_id' => $externalId,
                'note' => $note,
                'created_by_user_id' => Auth::id(),
            ]);

            $superseded = $origin === DormPayment::ORIGIN_1C
                ? $this->supersedeManual($payment)
                : 0;

            AuditLogService::log('dorm', 'payment_recorded', $payment, null, $payment->only([
                'student_id', 'paid_through', 'origin',
            ]) + ['superseded' => $superseded]);

            return $payment;
        });
    }

    /**
     * Ручные отметки, которые перекрывает эта строка из 1С.
     *
     * Перекрывает — значит закрывает тот же срок или больший: если обмен
     * говорит «оплачено по 30 сентября», ручная отметка «по 30 сентября» или
     * «по 15 сентября» больше ничего не добавляет. Она не удаляется, а
     * помечается замещённой: видно, что комендант работу делал, и видно, чем
     * её заменили.
     */
    private function supersedeManual(DormPayment $winner): int
    {
        return DormPayment::query()
            ->where('student_id', $winner->student_id)
            ->where('origin', DormPayment::ORIGIN_MANUAL)
            ->whereNull('superseded_by_id')
            ->whereDate('paid_through', '<=', $winner->paid_through)
            ->whereKeyNot($winner->id)
            ->update(['superseded_by_id' => $winner->id, 'updated_at' => now()]);
    }

    /** По какое число студент закрыт. Замещённые отметки не считаются. */
    public function paidThrough(int $studentId): ?Carbon
    {
        $value = DormPayment::query()
            ->where('student_id', $studentId)
            ->whereNull('superseded_by_id')
            ->max('paid_through');

        return $value === null ? null : Carbon::parse($value);
    }

    /**
     * Сводка по проживающим: по какое число закрыт и на сколько просрочил.
     *
     * Считается одним запросом на всех, а не запросом на строку: проживающих
     * может быть весь этаж, и «запрос на строку» в этом портале уже ловили.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function summary(CarbonInterface|string|null $on = null): Collection
    {
        $on = $on === null ? Carbon::today() : Carbon::parse($on)->startOfDay();

        $residents = DormPlacement::query()
            ->with(['student.group', 'room'])
            ->whereNull('moved_out_at')
            ->get();

        $paid = DormPayment::query()
            ->whereIn('student_id', $residents->pluck('student_id')->unique()->all())
            ->whereNull('superseded_by_id')
            ->selectRaw('student_id, max(paid_through) as paid_through')
            ->groupBy('student_id')
            ->pluck('paid_through', 'student_id');

        return $residents->map(function (DormPlacement $placement) use ($paid, $on): array {
            $student = $placement->student;
            $through = $paid->get($placement->student_id);
            $throughDate = $through === null ? null : Carbon::parse($through);

            return [
                'student_id' => $placement->student_id,
                'full_name' => trim(implode(' ', array_filter([
                    $student?->last_name,
                    $student?->first_name,
                    $student?->middle_name,
                ]))),
                'group' => $student?->group?->name,
                'room' => $placement->room?->number,
                'paid_through' => $throughDate?->toDateString(),
                // Разностью отметок времени, а не `diffInDays`: в Carbon 3 она
                // знаковая, и «на сколько позже» легко получить со знаком минус.
                // Отрицательных дней просрочки не бывает: закрытый вперёд
                // человек не «просрочил на минус пять», он просто не должен.
                'overdue_days' => $throughDate === null || $throughDate->greaterThanOrEqualTo($on)
                    ? 0
                    : intdiv($on->getTimestamp() - $throughDate->getTimestamp(), 86400),
                'never_paid' => $throughDate === null,
            ];
        })->sortByDesc('overdue_days')->values();
    }
}
