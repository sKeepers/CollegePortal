<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Портал, не знающий своего окружения, не называет себя боевым.
 *
 * Метка берётся из `VITE_APP_ENV`, которую задают при сборке. Пока умолчанием
 * стояло `production`, портал без этой переменной показывал зелёное `PROD`:
 * замерено 30.08.2026 на одноразовой базе, поднятой своим Vite. Стенд честен,
 * потому что переменную ему задают, — но умолчание страхует случай, когда её
 * забыли, и страховать оно обязано в сторону «не знаю».
 *
 * Ошибка в обе стороны стоит разного: «боевой» на стенде делает осторожным там,
 * где не нужно, «стенд» на боевом — беспечным там, где нужно.
 */
class EnvironmentIsNotGuessedAsProductionTest extends TestCase
{
    public function test_unknown_environment_is_not_called_production(): void
    {
        $path = base_path('../frontend/src/services/environmentService.js');

        if (! is_file($path)) {
            $this->markTestSkipped('фронтенд не смонтирован: файл окружения недоступен');
        }

        $source = (string) file_get_contents($path);

        $this->assertSame(
            1,
            preg_match("/const\s+DEFAULT_ENVIRONMENT\s*=\s*'([^']+)'/u", $source, $matches),
            'умолчание окружения не найдено — сторож смотрит не туда',
        );

        $this->assertNotSame('production', $matches[1], implode("\n", [
            'Умолчание окружения — `production`: не зная, где он работает, портал объявляет себя боевым.',
            'Зелёная метка PROD на стенде однажды остановит правку, которую надо сделать,',
            'или разрешит ту, которую делать нельзя. Умолчание должно говорить «не знаю».',
        ]));
    }
}
