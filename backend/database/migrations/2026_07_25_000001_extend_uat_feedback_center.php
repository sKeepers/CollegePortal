<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uat_feedback', function (Blueprint $table): void {
            $table->string('browser')->nullable()->after('environment');
            $table->text('user_agent')->nullable()->after('browser');
            $table->unsignedInteger('github_issue_number')->nullable()->after('resolution');
            $table->string('github_issue_url')->nullable()->after('github_issue_number');
            $table->string('github_issue_status', 50)->nullable()->after('github_issue_url');

            $table->index('app_version');
            $table->index('created_at');
            $table->index('github_issue_number');
        });

        Schema::create('uat_feedback_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feedback_id')->constrained('uat_feedback')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('old_status', 30)->nullable();
            $table->string('new_status', 30);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['feedback_id', 'created_at']);
        });

        Schema::create('uat_feedback_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feedback_id')->constrained('uat_feedback')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40)->default('admin')->index();
            $table->text('comment');
            $table->timestamps();

            $table->index(['feedback_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uat_feedback_comments');
        Schema::dropIfExists('uat_feedback_status_history');

        Schema::table('uat_feedback', function (Blueprint $table): void {
            $table->dropIndex(['app_version']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['github_issue_number']);
            $table->dropColumn([
                'browser',
                'user_agent',
                'github_issue_number',
                'github_issue_url',
                'github_issue_status',
            ]);
        });
    }
};
