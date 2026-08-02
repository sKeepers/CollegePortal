<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseBackupApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_requires_explicit_confirmation(): void
    {
        $this->withApiAuth()
            ->postJson('/api/admin/database-backups/manual-20260802-120000-11111111-1111-1111-1111-111111111111.dump/restore', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');
    }

    public function test_user_without_settings_permission_cannot_list_archives(): void
    {
        $user = $this->createApiUser(roleCode: 'operator');

        $this->withApiAuth($user)
            ->getJson('/api/admin/database-backups')
            ->assertForbidden();
    }
}
