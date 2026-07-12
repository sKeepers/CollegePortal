<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_status_periods', function (Blueprint $table): void {
            $table->string('period_status', 30)->default('planned')->after('status')->index();
            $table->timestamp('cancelled_at')->nullable()->after('comment');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable()->after('cancelled_by');
            $table->json('metadata')->nullable()->after('cancel_reason');
        });

        Schema::create('hr_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 80)->index();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('employee_status_period_id')->nullable()->constrained('employee_status_periods')->nullOnDelete();
            $table->foreignId('schedule_entry_id')->nullable()->constrained('schedule_entries')->nullOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->string('severity', 20)->default('info');
            $table->timestamp('read_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_events');
        Schema::table('employee_status_periods', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['period_status', 'cancelled_at', 'cancel_reason', 'metadata']);
        });
    }
};
