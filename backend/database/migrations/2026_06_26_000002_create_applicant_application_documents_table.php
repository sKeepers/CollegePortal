<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicant_application_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('applicant_application_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('title');
            $table->boolean('is_received')->default(false);
            $table->date('received_at')->nullable();
            $table->string('number')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['applicant_application_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_application_documents');
    }
};
