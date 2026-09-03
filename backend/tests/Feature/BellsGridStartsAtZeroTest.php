<?php

namespace Tests\Feature;

use App\Models\LessonTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Сетка звонков колледжа грузится целиком, вместе с нулевым занятием.
 *
 * Номер пары проверялся правилом `min:1, max:12` — числом, взятым из общего
 * представления о том, сколько пар бывает. У колледжа искусств их шестнадцать:
 * нулевое с 7:15 и пятнадцатое до 20:15, потому что индивидуальные занятия
 * расходятся по всему дню. Четыре занятия из шестнадцати такое правило
 * отвергало, а расписание на них ссылаться не могло вовсе.
 *
 * Проверяются обе границы и обе двери — загрузка файлом и правила расписания:
 * разойдясь, они дают сетку, которая загрузилась, но занять её нечем.
 */
class BellsGridStartsAtZeroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_the_whole_bell_grid_loads(): void
    {
        $csv = "Номер пары;Начало;Окончание\n";
        $times = [
            '07:15', '08:00', '08:45', '09:40', '10:25', '11:20', '12:05', '13:00',
            '13:45', '14:40', '15:25', '16:20', '17:05', '18:00', '18:45', '19:30',
        ];

        foreach ($times as $number => $start) {
            $end = date('H:i', strtotime($start) + 45 * 60);
            $csv .= "{$number};{$start};{$end}\n";
        }

        $result = $this->import('lesson_times', $csv, [
            'lesson_number' => 'Номер пары', 'starts_at' => 'Начало', 'ends_at' => 'Окончание',
        ]);

        $this->assertSame(0, $result['error_count'], 'сетка обязана загрузиться целиком');
        $this->assertSame(16, $result['created_count']);
        $this->assertSame('07:15', substr((string) LessonTime::where('lesson_number', 0)->firstOrFail()->starts_at, 0, 5));
        $this->assertSame(15, (int) LessonTime::max('lesson_number'));
    }

    /**
     * Расписание не отвергает нулевое занятие по номеру.
     *
     * Запрос заведомо неполон — групп, дисциплин и преподавателей в этом тесте нет,
     * и ответ обязан быть 422. Смысл проверки в том, **чего в ошибках нет**: номера
     * пары. Так сторож остаётся достижимым без выдуманного расписания — при прежнем
     * `min:1` номер попадал бы в тот же перечень.
     */
    public function test_the_schedule_does_not_reject_the_zero_lesson_by_its_number(): void
    {
        $response = $this->postJson('/api/schedule/preview', [
            'lesson_number' => 0,
            'starts_at' => '07:15',
            'ends_at' => '08:00',
            'group_id' => 999999,
            'subject_id' => 999999,
            'teacher_id' => 999999,
        ]);

        $response->assertStatus(422);
        $this->assertArrayNotHasKey('lesson_number', $response->json('errors') ?? [],
            'нулевое занятие отвергается по номеру');
        $this->assertArrayHasKey('group_id', $response->json('errors') ?? [],
            'проверка должна доходить до разбора полей, иначе она ничего не сторожит');
    }

    private function import(string $type, string $csv, array $mapping): array
    {
        $path = storage_path('framework/testing/bells.csv');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $csv);

        $jobId = (int) $this->post('/api/admin/import/preview', [
            'data_type' => $type,
            'file' => new UploadedFile($path, 'bells.csv', 'text/csv', null, true),
        ])->assertCreated()->json('data.id');

        return $this->postJson("/api/admin/import/{$jobId}/confirm", ['mode' => 'create', 'mapping' => $mapping])
            ->assertOk()
            ->json('data');
    }
}
