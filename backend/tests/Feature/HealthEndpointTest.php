<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_health_endpoint_returns_ok(): void
    {
        $this->getJson('/health/live')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['status', 'service', 'timestamp']);
    }

    public function test_ready_health_endpoint_checks_dependencies(): void
    {
        $this->getJson('/health/ready')
            ->assertOk()
            ->assertJsonStructure(['status', 'checks' => ['database', 'storage', 'cache'], 'timestamp']);
    }
}
