<?php

use App\Support\Admissions\AdmissionReferenceCatalogs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создает read-only foundation справочников приемной комиссии и базовые permissions.
     */
    public function up(): void
    {
        if (Schema::hasTable('reference_catalogs') && Schema::hasTable('reference_items')) {
            $this->seedReferenceCatalogs();
        }

        if (Schema::hasTable('permissions')) {
            $this->seedPermissions();
        }
    }

    /**
     * Удаляет только permissions этой миграции; справочники оставляет для защиты совместимости данных.
     */
    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('code', ['admissions.reference.view', 'admissions.reference.manage'])
            ->pluck('id');

        if (Schema::hasTable('permission_role')) {
            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        }

        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }

    /**
     * Идемпотентно добавляет системные reference catalogs/items.
     */
    private function seedReferenceCatalogs(): void
    {
        foreach (AdmissionReferenceCatalogs::catalogs() as $catalogData) {
            DB::table('reference_catalogs')->updateOrInsert(
                ['code' => $catalogData['code']],
                [
                    'name' => $catalogData['name'],
                    'description' => $catalogData['description'],
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $catalogId = DB::table('reference_catalogs')->where('code', $catalogData['code'])->value('id');

            foreach ($catalogData['items'] as $index => $item) {
                DB::table('reference_items')->updateOrInsert(
                    ['catalog_id' => $catalogId, 'code' => $item['code']],
                    [
                        'name' => $item['name'],
                        'sort_order' => ($index + 1) * 10,
                        'is_active' => true,
                        'metadata' => json_encode([
                            'is_system' => true,
                            'module' => 'admissions',
                            ...($item['metadata'] ?? []),
                        ], JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }

    /**
     * Идемпотентно добавляет permissions и привязывает их к существующим ролям.
     */
    private function seedPermissions(): void
    {
        $permissions = [
            [
                'module' => 'Admissions',
                'code' => 'admissions.reference.view',
                'name' => 'Приемная комиссия: справочники просмотр',
                'description' => 'Просмотр системных справочников приемной комиссии.',
            ],
            [
                'module' => 'Admissions',
                'code' => 'admissions.reference.manage',
                'name' => 'Приемная комиссия: справочники управление',
                'description' => 'Будущее управление справочниками приемной комиссии через защищенный admin flow.',
            ],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $permission['code']],
                [
                    ...$permission,
                    'system' => true,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $roleMap = [
            'admin' => ['admissions.reference.view', 'admissions.reference.manage'],
            'admission' => ['admissions.reference.view'],
            'director' => ['admissions.reference.view'],
            'study' => ['admissions.reference.view'],
            'academic_office' => ['admissions.reference.view'],
        ];

        foreach ($roleMap as $roleCode => $codes) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');

            if (! $roleId) {
                continue;
            }

            $permissionIds = DB::table('permissions')->whereIn('code', $codes)->pluck('id');

            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }
};
