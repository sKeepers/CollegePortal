<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicant_application_documents', function (Blueprint $table): void {
            $table->foreignId('document_type_id')->nullable()->after('applicant_application_id')->constrained('reference_items')->nullOnDelete();
            $table->string('status', 32)->default('missing')->after('title');
            $table->foreignId('received_by')->nullable()->after('received_at')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('received_by');
            $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable()->after('verified_by');
            $table->string('source', 50)->default('manual')->after('comment');
            $table->index(['applicant_application_id', 'status']);
            $table->unique(['applicant_application_id', 'document_type_id'], 'aad_application_document_type_unique');
        });

        Schema::create('applicant_document_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('applicant_application_document_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum_sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('checksum_sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_document_files');

        Schema::table('applicant_application_documents', function (Blueprint $table): void {
            $table->dropUnique('aad_application_document_type_unique');
            $table->dropIndex(['applicant_application_id', 'status']);
            $table->dropConstrainedForeignId('document_type_id');
            $table->dropConstrainedForeignId('received_by');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['status', 'verified_at', 'rejection_reason', 'source']);
        });
    }
};
