<?php

namespace Tests\Feature;

use App\Models\DashboardLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardLayoutApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_update_activate_and_reset_own_dashboard_layout(): void
    {
        $user = $this->createApiUser(roleCode: 'director');
        $payload = [
            'dashboard_type' => 'director',
            'name' => 'Мой Dashboard',
            'is_default' => true,
            'layout' => [
                'widgets' => [
                    ['id' => 'stats', 'order' => 0, 'size' => 'full', 'visible' => true],
                    ['id' => 'audit', 'order' => 1, 'size' => 'medium', 'visible' => false],
                ],
            ],
        ];

        $response = $this->withApiAuth($user)
            ->postJson('/api/dashboard/layouts', $payload)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Мой Dashboard')
            ->assertJsonPath('data.is_default', true);

        $layoutId = $response->json('data.id');

        $this->withApiAuth($user)
            ->getJson('/api/dashboard/layouts?dashboard_type=director')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.layout.widgets.1.visible', false);

        $this->withApiAuth($user)
            ->putJson("/api/dashboard/layouts/{$layoutId}", [
                ...$payload,
                'layout' => [
                    'widgets' => [
                        ['id' => 'audit', 'order' => 0, 'size' => 'large', 'visible' => true],
                        ['id' => 'stats', 'order' => 1, 'size' => 'full', 'visible' => true],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.layout.widgets.0.id', 'audit')
            ->assertJsonPath('data.layout.widgets.0.size', 'large');

        $this->withApiAuth($user)
            ->postJson("/api/dashboard/layouts/{$layoutId}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        $this->withApiAuth($user)
            ->postJson('/api/dashboard/layouts/reset', ['dashboard_type' => 'director'])
            ->assertOk();

        $this->assertDatabaseMissing('dashboard_layouts', ['id' => $layoutId]);
    }

    public function test_user_cannot_update_another_users_dashboard_layout(): void
    {
        $owner = $this->createApiUser(roleCode: 'admin');
        $intruder = $this->createApiUser(roleCode: 'teacher');
        $layout = DashboardLayout::query()->create([
            'user_id' => $owner->id,
            'dashboard_type' => 'admin',
            'name' => 'Мой Dashboard',
            'is_default' => true,
            'layout' => ['widgets' => [['id' => 'stats', 'order' => 0, 'size' => 'full', 'visible' => true]]],
        ]);

        $this->withApiAuth($intruder)
            ->putJson("/api/dashboard/layouts/{$layout->id}", [
                'dashboard_type' => 'teacher',
                'name' => 'Мой Dashboard',
                'is_default' => true,
                'layout' => ['widgets' => [['id' => 'stats', 'order' => 0, 'size' => 'small', 'visible' => false]]],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('dashboard_layouts', [
            'id' => $layout->id,
            'user_id' => $owner->id,
            'dashboard_type' => 'admin',
        ]);
    }
}
