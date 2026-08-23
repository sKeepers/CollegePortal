<?php

namespace App\Services;

use App\Models\DormConductRecord;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Провинности: запись, правка и дополнение.
 *
 * Правила заданы разбором `DORM-001` и решениями владельца от 22.08.2026, и все
 * три здесь, а не на экране:
 *
 * - запись **гаснет через год** — срок берётся из настройки, а не зашит;
 * - автор правит её **в течение суток**, дальше только дополнение;
 * - дополнение — **отдельная запись со ссылкой на первую**, а не переписанная
 *   строка: история не меняется задним числом, но ошибка исправима.
 */
class DormConductService
{
    public function record(
        Student $student,
        CarbonInterface|string $happenedOn,
        string $summary,
        ?string $description = null,
    ): DormConductRecord {
        $record = DormConductRecord::create([
            'student_id' => $student->id,
            'happened_on' => $happenedOn,
            'summary' => $summary,
            'description' => $description,
            'expires_on' => $this->expiresOn($happenedOn),
            'created_by_user_id' => Auth::id(),
        ]);

        AuditLogService::log('dorm.conduct', 'recorded', $record, null, $record->only([
            'student_id', 'happened_on', 'summary', 'expires_on',
        ]));

        return $record;
    }

    /**
     * Дополнение к записи.
     *
     * Дополнение относится к тому же студенту, что и исходная запись: указывать
     * его отдельно нельзя, иначе цепочка развалится на двух разных людей.
     */
    public function amend(DormConductRecord $parent, string $summary, ?string $description = null): DormConductRecord
    {
        if ($parent->parent_id !== null) {
            throw ValidationException::withMessages([
                'parent_id' => 'Дополнение пишется к исходной записи, а не к другому дополнению.',
            ]);
        }

        $record = DormConductRecord::create([
            'student_id' => $parent->student_id,
            'parent_id' => $parent->id,
            'happened_on' => Carbon::today(),
            'summary' => $summary,
            'description' => $description,
            'expires_on' => $this->expiresOn(Carbon::today()),
            'created_by_user_id' => Auth::id(),
        ]);

        AuditLogService::log('dorm.conduct', 'amended', $record, null, [
            'parent_id' => $parent->id,
            'summary' => $summary,
        ]);

        return $record;
    }

    /**
     * Правка своей записи в течение суток.
     *
     * Чужую не правит никто и никогда: запись подписана автором, и подпись
     * должна что-то значить. По истечении суток — только дополнение.
     */
    public function update(DormConductRecord $record, string $summary, ?string $description, ?User $editor = null): DormConductRecord
    {
        $editor ??= Auth::user();

        if ($record->created_by_user_id !== null && $editor?->id !== $record->created_by_user_id) {
            throw ValidationException::withMessages([
                'record' => 'Правит только автор записи. Допишите дополнение — оно встанет рядом и не перепишет сказанного.',
            ]);
        }

        // Разностью отметок времени, а не `diffInHours`: в Carbon 3 она
        // знаковая, и сравнение с порогом молча перестаёт срабатывать.
        $ageHours = intdiv(now()->getTimestamp() - $record->created_at->getTimestamp(), 3600);

        if ($ageHours >= DormConductRecord::EDIT_WINDOW_HOURS) {
            throw ValidationException::withMessages([
                'record' => 'Записи больше суток — править её поздно. Допишите дополнение: история останется, а поправка встанет рядом.',
            ]);
        }

        $old = $record->only(['summary', 'description']);
        $record->forceFill(['summary' => $summary, 'description' => $description])->save();

        AuditLogService::log('dorm.conduct', 'updated', $record, $old, $record->only(['summary', 'description']));

        return $record;
    }

    /** Через сколько запись перестаёт учитываться. */
    private function expiresOn(CarbonInterface|string $happenedOn): Carbon
    {
        $months = (int) SettingService::value('dorm', 'conduct_expires_months', 12);

        return Carbon::parse($happenedOn)->addMonths(max(1, $months));
    }
}
