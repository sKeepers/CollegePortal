<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fis_communication_logs', function (Blueprint $table): void {
            $table->id();
            $table->timestampTz('occurred_at');
            $table->uuid('request_id')->nullable();
            $table->string('direction')->default('outbound');
            $table->string('transport')->default('collegeportal_gateway');
            $table->string('method');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status');
            $table->unsignedSmallInteger('http_code')->nullable();
            $table->string('soap_fault_code')->nullable();
            $table->text('soap_fault_message')->nullable();
            $table->string('error_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['occurred_at', 'status']);
            $table->index(['method', 'occurred_at']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fis_communication_logs');
    }
};
