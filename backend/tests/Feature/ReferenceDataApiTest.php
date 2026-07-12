<?php

namespace Tests\Feature;

use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Services\ReferenceService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceDataApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_data_seeder_creates_system_catalogs(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $this->assertDatabaseHas('reference_catalogs', [
            'code' => 'education_forms',
            'name' => 'Формы обучения',
            'is_system' => true,
        ]);
        $this->assertDatabaseHas('reference_items', [
            'code' => 'full_time',
            'name' => 'Очная',
            'is_active' => true,
        ]);

        $this->assertSame(14, ReferenceCatalog::query()->count());
    }

    public function test_reference_service_returns_cached_catalog_options(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $items = ReferenceService::catalog('exam_types');
        $options = ReferenceService::options('exam_types');

        $this->assertGreaterThanOrEqual(4, $items->count());
        $this->assertContains('exam', array_column($options, 'value'));
        $this->assertSame('Экзамен', collect($options)->firstWhere('value', 'exam')['label']);
    }

    public function test_admin_can_manage_custom_catalog_and_items(): void
    {
        $catalogId = $this->withApiAuth()
            ->postJson('/api/admin/reference/catalogs', [
                'code' => 'custom_statuses',
                'name' => 'Пользовательские статусы',
                'description' => 'Тестовый справочник',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'custom_statuses')
            ->json('data.id');

        $itemId = $this->withApiAuth()
            ->postJson('/api/admin/reference/items', [
                'catalog_id' => $catalogId,
                'code' => 'first',
                'name' => 'Первый',
                'sort_order' => 10,
                'is_active' => true,
                'metadata' => ['color' => 'green'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Первый')
            ->assertJsonPath('data.metadata.color', 'green')
            ->json('data.id');

        $this->withApiAuth()
            ->putJson("/api/admin/reference/items/{$itemId}", [
                'catalog_id' => $catalogId,
                'code' => 'first',
                'name' => 'Первый обновленный',
                'sort_order' => 20,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->withApiAuth()
            ->getJson("/api/admin/reference/items?catalog_id={$catalogId}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withApiAuth()->deleteJson("/api/admin/reference/items/{$itemId}")->assertNoContent();
        $this->withApiAuth()->deleteJson("/api/admin/reference/catalogs/{$catalogId}")->assertNoContent();
    }

    public function test_system_catalog_and_system_items_cannot_be_deleted(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $catalog = ReferenceCatalog::where('code', 'education_forms')->firstOrFail();
        $item = ReferenceItem::where('catalog_id', $catalog->id)->where('code', 'full_time')->firstOrFail();

        $this->withApiAuth()
            ->deleteJson("/api/admin/reference/catalogs/{$catalog->id}")
            ->assertUnprocessable();

        $this->withApiAuth()
            ->deleteJson("/api/admin/reference/items/{$item->id}")
            ->assertUnprocessable();

        $this->withApiAuth()
            ->putJson("/api/admin/reference/items/{$item->id}", [
                'catalog_id' => $catalog->id,
                'code' => 'full_time',
                'name' => 'Очная',
                'sort_order' => 10,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }
}
