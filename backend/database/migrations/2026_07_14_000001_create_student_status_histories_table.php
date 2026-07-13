<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('status', 64)->index();
            $table->string('source')->default('student_contingent_doc')->index();
            $table->string('order_number')->nullable();
            $table->date('order_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('import_job_id')->nullable()->constrained('import_jobs')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'status', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_status_histories');
    }
};
