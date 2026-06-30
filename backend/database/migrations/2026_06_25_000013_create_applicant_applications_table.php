<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicant_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('education_program_id')->constrained()->cascadeOnDelete();
            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('education_base', 20)->default('after_9');
            $table->string('status', 40)->default('new');
            $table->date('submitted_at');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['status', 'education_program_id']);
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_applications');
    }
};
