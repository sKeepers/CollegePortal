<?php

namespace App\Services;

use App\Models\GiaProtocol;
use App\Models\GiaProtocolDecision;
use App\Models\Student;

/**
 * Ведомость решений комиссии: кто в протоколе и что решили по каждому.
 *
 * Строится **от состава группы**, а не от списка уже внесённых решений: секретарь
 * комиссии должен видеть всех, включая тех, по кому решения ещё нет, — иначе он не
 * заметит пропущенного, а пропущенный выпускник останется без диплома.
 */
class GiaProtocolService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function sheet(GiaProtocol $protocol): array
    {
        $students = $protocol->group_id === null
            ? collect()
            : Student::query()
                ->where('group_id', $protocol->group_id)
                ->orderBy('last_name')->orderBy('first_name')
                ->get(['id', 'last_name', 'first_name', 'middle_name']);

        $decisions = $protocol->decisions()->get()->keyBy('student_id');

        // Решение может быть и по человеку, которого в группе уже нет — отчислен,
        // переведён, восстановлен. Такие строки обязаны остаться видимыми: протокол
        // подписан, и вычёркивать из него нельзя.
        $extra = $decisions->reject(fn (GiaProtocolDecision $decision): bool => $students->contains('id', $decision->student_id));

        $rows = $students->map(fn (Student $student): array => $this->row(
            $student->id,
            $this->fullName($student),
            $decisions->get($student->id),
            inGroup: true,
        ))->all();

        foreach ($extra as $decision) {
            $rows[] = $this->row($decision->student_id, $decision->student_name, $decision, inGroup: false);
        }

        return $rows;
    }

    /**
     * Записать решения.
     *
     * Пустой результат снимает решение: внесли не тому — должно быть чем убрать.
     *
     * @param array<int, array{student_id: int, result?: ?string, mark?: ?string, qualification?: ?string, note?: ?string}> $rows
     * @return array{saved: int, removed: int}
     */
    public function saveDecisions(GiaProtocol $protocol, array $rows): array
    {
        $result = ['saved' => 0, 'removed' => 0];

        foreach ($rows as $row) {
            $studentId = (int) ($row['student_id'] ?? 0);
            $existing = $protocol->decisions()->where('student_id', $studentId)->first();
            $decision = trim((string) ($row['result'] ?? ''));

            if ($decision === '') {
                if ($existing !== null) {
                    $existing->delete();
                    $result['removed']++;
                }

                continue;
            }

            $student = Student::query()->find($studentId);

            // `firstOrNew` + `save`, а не `updateOrCreate`: последний внутри транзакции
            // открывает точку сохранения на каждую строку, а таблица блокировок одна на
            // весь сервер. Выпуск — это сотня строк за раз.
            $model = $existing ?? new GiaProtocolDecision([
                'gia_protocol_id' => $protocol->id,
                'student_id' => $studentId,
            ]);

            $model->fill([
                'student_name' => $model->student_name ?: $this->fullName($student),
                'result' => $decision,
                'mark' => $row['mark'] ?? null,
                'qualification' => $row['qualification'] ?? null,
                'note' => $row['note'] ?? null,
            ])->save();

            $result['saved']++;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function row(int $studentId, ?string $name, ?GiaProtocolDecision $decision, bool $inGroup): array
    {
        return [
            'student_id' => $studentId,
            'name' => $name,
            'in_group' => $inGroup,
            'result' => $decision?->result,
            'mark' => $decision?->mark,
            'qualification' => $decision?->qualification,
            'note' => $decision?->note,
        ];
    }

    private function fullName(?Student $student): ?string
    {
        if ($student === null) {
            return null;
        }

        return trim(implode(' ', array_filter([
            $student->last_name, $student->first_name, $student->middle_name,
        ]))) ?: null;
    }
}
