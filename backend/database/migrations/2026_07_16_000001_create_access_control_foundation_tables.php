<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_points', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('location')->nullable();
            $table->string('direction_mode', 32)->default('both');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique('name');
            $table->index(['active', 'direction_mode']);
        });

        Schema::create('access_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('access_point_id')->nullable()->constrained('access_points')->nullOnDelete();
            $table->string('type', 32);
            $table->string('name');
            $table->string('identifier')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique('identifier');
            $table->index(['access_point_id', 'active']);
            $table->index(['type', 'active']);
        });

        Schema::create('access_pass_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('nonce', 64)->unique();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('device_identifier')->nullable();
            $table->timestamps();

            $table->index(['person_id', 'expires_at']);
            $table->index(['expires_at', 'used_at', 'revoked_at']);
        });

        Schema::create('access_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('entry_event_id')->nullable()->constrained('access_events')->nullOnDelete();
            $table->foreignId('exit_event_id')->nullable()->constrained('access_events')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('status', 32)->default('open');
            $table->timestamps();

            $table->index(['person_id', 'status', 'started_at']);
        });

        Schema::create('access_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('scope', 32)->default('global');
            $table->json('conditions')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['scope', 'active']);
        });

        Schema::create('access_operator_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('access_point_id')->nullable()->constrained('access_points')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('status', 32)->default('open');
            $table->timestamps();

            $table->index(['operator_id', 'status']);
            $table->index(['access_point_id', 'started_at']);
        });

        Schema::create('access_denials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('access_event_id')->constrained('access_events')->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('reason_code', 64);
            $table->string('reason')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['reason_code', 'created_at']);
            $table->index(['person_id', 'created_at']);
        });

        Schema::create('access_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('access_event_id')->nullable()->constrained('access_events')->nullOnDelete();
            $table->string('action', 64);
            $table->string('request_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['action', 'created_at']);
            $table->index('request_id');
        });

        Schema::table('access_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('access_events', 'person_id')) {
                $table->foreignId('person_id')->nullable()->after('id')->constrained('people')->nullOnDelete();
            }
            if (! Schema::hasColumn('access_events', 'access_point_id')) {
                $table->foreignId('access_point_id')->nullable()->after('person_id')->constrained('access_points')->nullOnDelete();
            }
            if (! Schema::hasColumn('access_events', 'device_id')) {
                $table->foreignId('device_id')->nullable()->after('access_point_id')->constrained('access_devices')->nullOnDelete();
            }
            if (! Schema::hasColumn('access_events', 'operator_id')) {
                $table->foreignId('operator_id')->nullable()->after('device_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('access_events', 'request_id')) {
                $table->string('request_id')->nullable()->after('operator_id');
            }
            if (! Schema::hasColumn('access_events', 'reason_code')) {
                $table->string('reason_code', 64)->nullable()->after('result');
            }
            if (! Schema::hasColumn('access_events', 'occurred_at')) {
                $table->timestamp('occurred_at')->nullable()->after('event_time');
            }
        });

        Schema::table('access_events', function (Blueprint $table): void {
            $table->index(['person_id', 'event_time']);
            $table->index(['access_point_id', 'event_time']);
            $table->index('request_id');
            $table->index(['reason_code', 'event_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_audit_events');
        Schema::dropIfExists('access_denials');
        Schema::dropIfExists('access_operator_shifts');
        Schema::dropIfExists('access_rules');
        Schema::dropIfExists('access_sessions');
        Schema::dropIfExists('access_pass_tokens');

        Schema::table('access_events', function (Blueprint $table): void {
            $table->dropIndex(['person_id', 'event_time']);
            $table->dropIndex(['access_point_id', 'event_time']);
            $table->dropIndex(['request_id']);
            $table->dropIndex(['reason_code', 'event_time']);

            if (Schema::hasColumn('access_events', 'person_id')) {
                $table->dropConstrainedForeignId('person_id');
            }
            if (Schema::hasColumn('access_events', 'access_point_id')) {
                $table->dropConstrainedForeignId('access_point_id');
            }
            if (Schema::hasColumn('access_events', 'device_id')) {
                $table->dropConstrainedForeignId('device_id');
            }
            if (Schema::hasColumn('access_events', 'operator_id')) {
                $table->dropConstrainedForeignId('operator_id');
            }
            foreach (['request_id', 'reason_code', 'occurred_at'] as $column) {
                if (Schema::hasColumn('access_events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('access_devices');
        Schema::dropIfExists('access_points');
    }
};
