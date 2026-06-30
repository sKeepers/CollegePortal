<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicant_application_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('applicant_application_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['applicant_application_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_application_events');
    }
};
