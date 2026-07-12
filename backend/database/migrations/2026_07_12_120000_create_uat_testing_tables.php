<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uat_test_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('role_code', 50)->index();
            $table->foreignId('tester_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('not_started')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('summary')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('uat_test_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('test_run_id')->constrained('uat_test_runs')->cascadeOnDelete();
            $table->string('scenario_code');
            $table->string('status', 30)->default('not_started')->index();
            $table->text('comment')->nullable();
            $table->text('actual_result')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->timestamps();
            $table->unique(['test_run_id', 'scenario_code']);
        });

        Schema::create('uat_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role_code', 50)->nullable()->index();
            $table->string('category', 40)->index();
            $table->string('severity', 40)->index();
            $table->string('title');
            $table->text('description');
            $table->text('expected_result')->nullable();
            $table->text('actual_result')->nullable();
            $table->text('page_url')->nullable();
            $table->string('app_version')->nullable();
            $table->string('build_hash')->nullable();
            $table->string('environment')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->string('status', 30)->default('new')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uat_feedback');
        Schema::dropIfExists('uat_test_results');
        Schema::dropIfExists('uat_test_runs');
    }
};
