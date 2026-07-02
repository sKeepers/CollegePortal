<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_identity_id')->nullable()->constrained('digital_identities')->nullOnDelete();
            $table->string('entity_type', 32)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('direction', 16)->default('in');
            $table->timestamp('event_time');
            $table->string('access_point')->nullable();
            $table->string('device_name')->nullable();
            $table->string('result', 32)->default('allowed');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['result', 'event_time']);
            $table->index(['digital_identity_id', 'event_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_events');
    }
};
