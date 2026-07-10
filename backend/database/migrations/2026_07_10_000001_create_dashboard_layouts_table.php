<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_layouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('dashboard_type', 80);
            $table->string('name', 120);
            $table->json('layout');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'dashboard_type', 'name']);
            $table->index(['user_id', 'dashboard_type', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_layouts');
    }
};
