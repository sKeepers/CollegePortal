<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('person_type')->nullable()->after('last_login_at');
            $table->unsignedBigInteger('person_id')->nullable()->after('person_type');
            $table->index(['person_type', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['person_type', 'person_id']);
            $table->dropColumn(['person_type', 'person_id']);
        });
    }
};
