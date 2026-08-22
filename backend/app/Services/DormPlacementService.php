<?php

namespace App\Services;

use App\Models\DormPlacement;
use App\Models\DormRoom;
use App\Models\Student;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Заселение, переселение и выселение.
 *
 * Правила здесь, а не в контроллере, потому что каждое из них легко нарушить
 * руками, а последствия видны не сразу:
 *
 * - **у студента одно действующее заселение.** Второе означает, что из первой
 *   комнаты его не выселили, и обе комнаты считают его своим — вместимость
 *   перестаёт сходиться;
 * - **переселение — это не правка строки**, а закрытие прежнего заселения и
 *   открытие нового. Правкой на месте стёрлась бы история переселений, а она
 *   как раз и нужна заместителю по воспитательной работе;
 * - **признак «проживающий» следует за фактом.** Он живёт в карточке студента,
 *   и если не двигать его вместе с заселением, признак и место разойдутся —
 *   а по признаку строятся списки.
 */
class DormPlacementService
{
    public function place(Student $student, DormRoom $room, CarbonInterface|string $movedInAt, ?string $basis = null, ?string $note = null): DormPlacement
    {
        $this->assertRoomTakesOneMore($room);

        $open = $this->openPlacement($student);

        if ($open !== null) {
            throw ValidationException::withMessages([
                'student_id' => 'Студент уже заселён в комнату '.$open->room?->number.'. Переселите его — тогда прежнее заселение закроется, а история останется.',
            ]);
        }

        return DB::transaction(function () use ($student, $room, $movedInAt, $basis, $note): DormPlacement {
            $placement = DormPlacement::create([
                'dorm_room_id' => $room->id,
                'student_id' => $student->id,
                'moved_in_at' => $movedInAt,
                'basis' => $basis,
                'note' => $note,
                'created_by_user_id' => Auth::id(),
            ]);

            $this->syncResidentFlag($student);

            AuditLogService::log('dorm', 'placed', $placement, null, $placement->only(['dorm_room_id', 'student_id', 'moved_in_at']));

            return $placement;
        });
    }

    /** Переселение: прежнее заселение закрывается, новое открывается. */
    public function relocate(Student $student, DormRoom $room, CarbonInterface|string $movedAt, ?string $basis = null, ?string $note = null): DormPlacement
    {
        $open = $this->openPlacement($student);

        if ($open === null) {
            throw ValidationException::withMessages([
                'student_id' => 'Студент нигде не заселён — переселять не из чего. Заселите его.',
            ]);
        }

        if ($open->dorm_room_id === $room->id) {
            throw ValidationException::withMessages([
                'dorm_room_id' => 'Студент уже живёт в этой комнате.',
            ]);
        }

        $this->assertRoomTakesOneMore($room);

        return DB::transaction(function () use ($student, $room, $movedAt, $basis, $note, $open): DormPlacement {
            $old = $open->only(['dorm_room_id', 'moved_out_at']);
            $open->forceFill(['moved_out_at' => $movedAt])->save();

            $placement = DormPlacement::create([
                'dorm_room_id' => $room->id,
                'student_id' => $student->id,
                'moved_in_at' => $movedAt,
                'basis' => $basis,
                'note' => $note,
                'created_by_user_id' => Auth::id(),
            ]);

            $this->syncResidentFlag($student);

            AuditLogService::log('dorm', 'relocated', $placement, $old, $placement->only(['dorm_room_id', 'moved_in_at']));

            return $placement;
        });
    }

    public function moveOut(Student $student, CarbonInterface|string $movedOutAt, ?string $note = null): DormPlacement
    {
        $open = $this->openPlacement($student);

        if ($open === null) {
            throw ValidationException::withMessages([
                'student_id' => 'Студент нигде не заселён — выселять некого.',
            ]);
        }

        if (strtotime((string) $movedOutAt) < strtotime((string) $open->moved_in_at)) {
            throw ValidationException::withMessages([
                'moved_out_at' => 'Дата выселения раньше даты заселения.',
            ]);
        }

        return DB::transaction(function () use ($student, $open, $movedOutAt, $note): DormPlacement {
            $old = $open->only(['moved_out_at']);
            $open->forceFill([
                'moved_out_at' => $movedOutAt,
                'note' => $note ?? $open->note,
            ])->save();

            $this->syncResidentFlag($student);

            AuditLogService::log('dorm', 'moved_out', $open, $old, $open->only(['moved_out_at']));

            return $open->fresh(['room', 'student']);
        });
    }

    /** Сколько человек живёт в комнате сейчас. */
    public function occupancy(DormRoom $room): int
    {
        return DormPlacement::query()
            ->where('dorm_room_id', $room->id)
            ->whereNull('moved_out_at')
            ->count();
    }

    public function openPlacement(Student $student): ?DormPlacement
    {
        return DormPlacement::query()
            ->with('room')
            ->where('student_id', $student->id)
            ->whereNull('moved_out_at')
            ->orderByDesc('moved_in_at')
            ->orderByDesc('id')
            ->first();
    }

    private function assertRoomTakesOneMore(DormRoom $room): void
    {
        if (! $room->is_active) {
            throw ValidationException::withMessages([
                'dorm_room_id' => 'Комната выведена из обращения.',
            ]);
        }

        $occupied = $this->occupancy($room);

        if ($room->capacity > 0 && $occupied >= $room->capacity) {
            throw ValidationException::withMessages([
                'dorm_room_id' => "В комнате {$room->number} мест нет: вместимость {$room->capacity}, живут {$occupied}.",
            ]);
        }
    }

    /**
     * Признак «проживающий» — это ответ на вопрос «живёт ли», а не «где».
     *
     * Держим его в согласии с заселениями: разойдясь однажды, они дальше врут
     * молча, а списки строятся именно по признаку.
     */
    private function syncResidentFlag(Student $student): void
    {
        $lives = DormPlacement::query()
            ->where('student_id', $student->id)
            ->whereNull('moved_out_at')
            ->exists();

        if ((bool) $student->is_resident !== $lives) {
            $student->forceFill(['is_resident' => $lives])->save();
        }
    }
}
