<?php

namespace App\Services\Import;

use App\Models\LessonTime;
use Illuminate\Database\Eloquent\Model;

/**
 * Загрузка сетки звонков файлом.
 *
 * Строка файла — одна пара: номер, начало, окончание. Дальше расписание берёт
 * время отсюда по номеру пары, и набирать его в каждой строке уже не нужно.
 */
class LessonTimeImportHandler extends AbstractImportHandler
{
    public function type(): string { return 'lesson_times'; }
    public function label(): string { return 'Сетка звонков'; }
    public function modelClass(): string { return LessonTime::class; }
    public function keyFields(): array { return ['lesson_number']; }

    public function fields(): array
    {
        return [
            'lesson_number' => ['label' => 'Номер пары', 'required' => true, 'aliases' => ['номер пары', 'пара', 'номер', 'lesson_number']],
            'starts_at' => ['label' => 'Начало', 'required' => true, 'aliases' => ['начало', 'начало пары', 'starts_at', 'time_start']],
            'ends_at' => ['label' => 'Окончание', 'required' => true, 'aliases' => ['окончание', 'конец', 'конец пары', 'ends_at', 'time_end']],
            'label' => ['label' => 'Название', 'required' => false, 'aliases' => ['название', 'подпись', 'label']],
            'is_active' => ['label' => 'Действует', 'required' => false, 'aliases' => ['действует', 'активна', 'is_active']],
        ];
    }

    public function templateHeaders(): array
    {
        return ['Номер пары', 'Начало', 'Окончание', 'Название', 'Действует'];
    }

    public function templateExample(): array
    {
        return ['1', '08:30', '10:05', 'Первая пара', 'да'];
    }

    /**
     * Время приводится к «ЧЧ:ММ». Excel любит отдавать «08:30:00», а человек —
     * писать «8:30»: и то и другое должно грузиться без правки файла.
     */
    public function prepare(array $data): array
    {
        foreach (['starts_at', 'ends_at'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));

            if ($value !== '' && preg_match('/^(\d{1,2}):(\d{2})/', $value, $match)) {
                $data[$key] = sprintf('%02d:%s', (int) $match[1], $match[2]);
            }
        }

        $active = mb_strtolower(trim((string) ($data['is_active'] ?? '')));
        $data['is_active'] = $active === '' ? true : ! in_array($active, ['нет', 'no', '0', 'false'], true);

        return $data;
    }

    public function rules(): array
    {
        return [
            'lesson_number' => ['required', 'integer', 'min:1', 'max:12'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'label' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    public function findExisting(array $data): ?Model
    {
        return LessonTime::where('lesson_number', (int) ($data['lesson_number'] ?? 0))->first();
    }
}
