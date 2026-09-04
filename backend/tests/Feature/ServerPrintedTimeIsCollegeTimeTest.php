<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Час, напечатанный сервером, — тоже час колледжа.
 *
 * Что охраняется, словами: где сервер сам собирает строку времени для
 * человека, он обязан перевести её в пояс колледжа. Экран перевести мало —
 * готовую строку он не трогает, и такое место незаметно ровно потому, что
 * выглядит уже отформатированным.
 *
 * Замерено 03.09.2026 в браузере на стенде, в 21:47 по колледжу: рабочий стол
 * показывал в «недавней активности» `18:47`, а журнал действий на соседнем
 * экране — `21:47`. **Одно и то же событие, два часа, разница ровно в пояс** —
 * и никакого признака, какой из них верный. Виновата была одна строка:
 * `DashboardAnalyticsService` печатал `created_at` без перевода.
 *
 * Сторож нарочно **не** ищет `->format(` по исходникам: у времени начала пары
 * (`starts_at`, колонка `time`) пояса нет вовсе, и перевод её сломал бы, — а
 * сторож, краснеющий на исправном, будет отключён. Проверяется поведение: во
 * сколько событие произошло, во столько и напечатано.
 */
class ServerPrintedTimeIsCollegeTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_recent_activity_widget_prints_the_college_hour(): void
    {
        $this->withApiAuth();

        // 06:15 UTC — это 09:15 по колледжу.
        AuditLog::create([
            'created_at' => '2026-09-10 06:15:00',
            'action' => 'ПРОБА',
            'module' => 'проба',
            'entity_type' => 'проба',
        ]);

        $audit = $this->getJson('/api/dashboard/analytics/executive')
            ->assertOk()
            ->json('data.audit');

        $this->assertNotEmpty($audit, 'Событие в сводку не попало — сторож смотрит не туда.');

        $times = array_column($audit, 'time');

        $this->assertContains('10.09 09:15', $times, 'Событие в 09:15 по колледжу напечатано другим часом: '.implode(', ', $times));
        $this->assertNotContains('10.09 06:15', $times, 'Напечатан час UTC, а не час колледжа.');
    }
}
