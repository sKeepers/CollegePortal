<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_update_and_reset_settings(): void
    {
        $this->withApiAuth()
            ->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonPath('data.general.0.key', 'college_full_name');

        $this->withApiAuth()
            ->putJson('/api/admin/settings', [
                'settings' => [
                    ['group' => 'general', 'key' => 'college_short_name', 'value' => 'Тестовый колледж'],
                    ['group' => 'identity', 'key' => 'duplicate_scan_window_seconds', 'value' => 5],
                    ['group' => 'hr', 'key' => 'weekday_workday_end', 'value' => '17:00'],
                ],
            ])
            ->assertOk()
            ->assertJsonFragment(['key' => 'college_short_name', 'value' => 'Тестовый колледж']);

        $this->assertSame('17:00', Setting::where('group', 'hr')->where('key', 'weekday_workday_end')->firstOrFail()->value);

        $this->assertDatabaseHas('settings', [
            'group' => 'identity',
            'key' => 'duplicate_scan_window_seconds',
        ]);
        $this->assertSame(5, Setting::where('group', 'identity')->where('key', 'duplicate_scan_window_seconds')->firstOrFail()->value);

        $this->withApiAuth()
            ->putJson('/api/admin/settings', ['reset_to_defaults' => true])
            ->assertOk();

        $this->assertSame(2, Setting::where('group', 'identity')->where('key', 'duplicate_scan_window_seconds')->firstOrFail()->value);
    }

    /**
     * На production настройки меняются только после подтверждения. Проверяется
     * не только сам запрет, но и то, что подтвердить возможно: раньше флаг
     * требовался, а отправить его было нечем, и настройки боевого портала не
     * сохранялись вообще никак.
     */
    public function test_production_settings_require_a_confirmation_that_works(): void
    {
        $this->app['env'] = 'production';

        $payload = [
            'settings' => [
                ['group' => 'general', 'key' => 'college_short_name', 'value' => 'СККИ'],
            ],
        ];

        $this->withApiAuth()
            ->putJson('/api/admin/settings', $payload)
            ->assertForbidden()
            ->assertJsonPath('requires_production_confirmation', true);

        $this->assertNotSame('СККИ', Setting::query()->where('key', 'college_short_name')->value('value'));

        $this->withApiAuth()
            ->putJson('/api/admin/settings', $payload + ['confirm_production' => true])
            ->assertOk()
            ->assertJsonFragment(['key' => 'college_short_name', 'value' => 'СККИ']);
    }

    public function test_public_settings_return_only_public_values(): void
    {
        $this->getJson('/api/settings/public')
            ->assertOk()
            ->assertJsonPath('data.general.college_short_name', 'СККИ')
            ->assertJsonPath('data.identity.duplicate_scan_window_seconds', 2)
            ->assertJsonMissingPath('data.integrations.frdo_mode');
    }

    public function test_unknown_setting_is_rejected(): void
    {
        $this->withApiAuth()
            ->putJson('/api/admin/settings', [
                'settings' => [
                    ['group' => 'secret', 'key' => 'plain_password', 'value' => '123'],
                ],
            ])
            ->assertUnprocessable();
    }
}
