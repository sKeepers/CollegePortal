<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Удаление в два шага и корзина.
 *
 * Правило владельца от 10.08.2026: удаляет только администратор. Роль, нашедшая
 * ошибочно заведённую карточку, помечает её на удаление и объясняет причину;
 * администратор проверяет и удаляет. Удаление не окончательное — запись уходит
 * в корзину и вычищается оттуда отдельно и вручную.
 *
 * Форма заявки повторяет `journal_edit_requests`: тот же поток «попросил —
 * проверили — решили», и незачем заводить второй его вид.
 */
return new class extends Migration
{
    /** Карточки людей: именно про них сказано «карточка создана ошибочно». */
    private const SOFT_DELETED_TABLES = ['students', 'teachers', 'employees'];

    public function up(): void
    {
        Schema::create('deletion_requests', function (Blueprint $table): void {
            $table->id();
            // Полиморфная связь: заявка одинаково describes студента,
            // преподавателя и сотрудника, а список сущностей будет расти.
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            // Подпись сохраняется на момент заявки: карточку удалят, а в очереди
            // и в журнале должно остаться видно, о ком шла речь.
            $table->string('subject_label')->nullable();
            $table->text('reason');
            $table->string('status')->default('pending');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        foreach (self::SOFT_DELETED_TABLES as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->softDeletes();
                });
            }
        }

        $this->seedPermissions();
    }

    public function down(): void
    {
        $this->removePermissions();

        foreach (self::SOFT_DELETED_TABLES as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropSoftDeletes();
                });
            }
        }

        Schema::dropIfExists('deletion_requests');
    }

    /**
     * Право просить об удалении получают роли, которые ведут карточки; право
     * решать и чистить корзину — никто, кроме администратора, он проходит по
     * `Gate::before`. Выдавать его роли отдельно не нужно и опасно.
     */
    private function seedPermissions(): void
    {
        $now = now();

        $permissions = [
            ['code' => 'trash.request', 'name' => 'Удаление: пометить карточку', 'description' => 'Пометить ошибочно заведённую карточку на удаление с указанием причины.'],
            ['code' => 'trash.manage', 'name' => 'Удаление: решение и корзина', 'description' => 'Одобрение и отклонение заявок, восстановление и окончательная очистка корзины.'],
        ];

        foreach ($permissions as $permission) {
            if (DB::table('permissions')->where('code', $permission['code'])->exists()) {
                continue;
            }

            DB::table('permissions')->insert($permission + [
                'module' => 'System',
                'system' => true,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $requestId = DB::table('permissions')->where('code', 'trash.request')->value('id');
        $roleIds = DB::table('roles')
            ->whereIn('code', ['study', 'study_records', 'hr', 'deputy', 'academic_office', 'admission'])
            ->pluck('id');

        $existing = DB::table('permission_role')->where('permission_id', $requestId)->pluck('role_id')->all();

        $rows = $roleIds
            ->reject(fn (int $roleId): bool => in_array($roleId, $existing, true))
            ->map(fn (int $roleId): array => ['role_id' => $roleId, 'permission_id' => $requestId])
            ->all();

        if ($rows !== []) {
            DB::table('permission_role')->insert($rows);
        }
    }

    private function removePermissions(): void
    {
        $ids = DB::table('permissions')->whereIn('code', ['trash.request', 'trash.manage'])->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
