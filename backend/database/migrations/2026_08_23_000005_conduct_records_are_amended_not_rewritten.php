<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Провинность исправляется дополнением, а не переписыванием.
 *
 * Правило из разбора `DORM-001`: автор правит запись в течение суток, дальше
 * только отдельная запись-дополнение со ссылкой на первую. Так история не
 * переписывается задним числом, а ошибка всё же исправима.
 *
 * Ссылка нужна и для чтения: дополнения показываются при исходной записи, а не
 * отдельными строками, иначе список превращается в набор обрывков.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('dorm_conduct_records', 'parent_id')) {
            return;
        }

        Schema::table('dorm_conduct_records', function (Blueprint $table): void {
            $table->foreignId('parent_id')->nullable()->after('student_id')
                ->constrained('dorm_conduct_records')->nullOnDelete();
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('dorm_conduct_records', 'parent_id')) {
            return;
        }

        Schema::table('dorm_conduct_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
