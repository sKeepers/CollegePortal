<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 32)->nullable()->unique();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('access_points', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('building_id')->constrained('buildings')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32)->nullable()->unique();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['building_id', 'name']);
        });

        // Сканеры присылают название точки строкой и продолжат присылать: менять
        // прошивку ради справочника нельзя. Связь заполняется при разборе скана,
        // а событиям, снятым до появления справочника, остается null.
        Schema::table('access_events', function (Blueprint $table): void {
            $table->foreignId('access_point_id')->nullable()->after('digital_identity_id')
                ->constrained('access_points')->nullOnDelete();
            $table->index(['access_point_id', 'event_time']);
        });
    }

    public function down(): void
    {
        Schema::table('access_events', function (Blueprint $table): void {
            $table->dropIndex(['access_point_id', 'event_time']);
            $table->dropConstrainedForeignId('access_point_id');
        });

        Schema::dropIfExists('access_points');
        Schema::dropIfExists('buildings');
    }
};
