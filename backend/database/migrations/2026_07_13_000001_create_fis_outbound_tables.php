<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fis_outbound_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('package_type');
            $table->string('external_campaign_id')->nullable();
            $table->string('source_entity_type')->nullable();
            $table->unsignedBigInteger('source_entity_id')->nullable();
            $table->string('schema_version');
            $table->string('environment')->default('test');
            $table->string('status')->default('draft');
            $table->string('payload_path')->nullable();
            $table->string('payload_sha256')->nullable();
            $table->string('package_id')->nullable();
            $table->string('request_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->json('response_metadata')->nullable();
            $table->timestamps();
            $table->index(['environment', 'status']);
            $table->index(['package_type', 'schema_version']);
            $table->index(['source_entity_type', 'source_entity_id']);
        });

        Schema::create('fis_outbound_package_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fis_outbound_package_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('status')->nullable();
            $table->string('request_id')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['event_type', 'created_at']);
        });

        Schema::create('fis_external_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('external_type');
            $table->string('external_id');
            $table->string('environment')->default('test');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['entity_type', 'entity_id', 'external_type', 'environment'], 'fis_external_mappings_unique');
            $table->index(['external_type', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fis_external_mappings');
        Schema::dropIfExists('fis_outbound_package_events');
        Schema::dropIfExists('fis_outbound_packages');
    }
};
