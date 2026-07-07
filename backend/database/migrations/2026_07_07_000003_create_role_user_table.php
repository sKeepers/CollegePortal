<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('role_user')) {
            Schema::create('role_user', function (Blueprint $table) {
                $table->foreignId('role_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->boolean('is_primary')->default(false);
                $table->timestamps();
                $table->primary(['role_id', 'user_id']);
            });
        }

        User::query()
            ->whereNotNull('role_id')
            ->select(['id', 'role_id'])
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    DB::table('role_user')->updateOrInsert(
                        ['role_id' => $user->role_id, 'user_id' => $user->id],
                        ['is_primary' => true, 'created_at' => now(), 'updated_at' => now()]
                    );
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
    }
};
