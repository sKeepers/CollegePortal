<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category');
            $table->string('entity_type');
            $table->string('numbering_pattern')->default('СПР-{YEAR}-{NUMBER:05}');
            $table->boolean('requires_registration')->default(true);
            $table->boolean('requires_qr')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('document_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_type_id')->constrained('document_types')->cascadeOnDelete();
            $table->string('name');
            $table->string('version');
            $table->string('status')->default('draft');
            $table->string('source_format')->default('docx');
            $table->string('template_path');
            $table->json('output_formats')->nullable();
            $table->json('variables_schema')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['document_type_id', 'status']);
        });

        Schema::create('generated_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->foreignId('document_template_id')->constrained('document_templates')->restrictOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('registration_number')->nullable()->unique();
            $table->date('issue_date');
            $table->string('status')->default('generated');
            $table->string('output_docx_path')->nullable();
            $table->string('output_pdf_path')->nullable();
            $table->json('payload_snapshot')->nullable();
            $table->string('payload_hash');
            $table->string('verification_token_hash');
            $table->string('verification_public_id')->unique();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedInteger('reprint_count')->default(0);
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['status', 'issue_date']);
        });

        Schema::create('document_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('generated_document_id')->constrained('generated_documents')->cascadeOnDelete();
            $table->string('event_type');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('document_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_type_id')->constrained('document_types')->cascadeOnDelete();
            $table->unsignedInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->string('prefix')->nullable();
            $table->timestamps();
            $table->unique(['document_type_id', 'year']);
        });

        Schema::create('student_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('order_type');
            $table->string('order_number');
            $table->date('order_date');
            $table->date('effective_date')->nullable();
            $table->foreignId('from_group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->foreignId('to_group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->unsignedInteger('from_course')->nullable();
            $table->unsignedInteger('to_course')->nullable();
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->string('source')->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['student_id', 'order_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_orders');
        Schema::dropIfExists('document_number_sequences');
        Schema::dropIfExists('document_events');
        Schema::dropIfExists('generated_documents');
        Schema::dropIfExists('document_templates');
        Schema::dropIfExists('document_types');
    }
};
