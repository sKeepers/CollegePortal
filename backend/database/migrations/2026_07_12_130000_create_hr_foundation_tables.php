<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('type')->nullable();
            $table->unsignedBigInteger('head_employee_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('category')->nullable();
            $table->boolean('is_teaching_position')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('employee_number')->unique();
            $table->string('status')->index();
            $table->string('employment_type')->index();
            $table->date('hired_at');
            $table->date('dismissed_at')->nullable();
            $table->foreignId('primary_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('primary_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->decimal('workload_rate', 4, 2)->nullable();
            $table->boolean('is_teacher')->default(false)->index();
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::table('departments', function (Blueprint $table): void {
            $table->foreign('head_employee_id')->references('id')->on('employees')->nullOnDelete();
        });

        Schema::create('employee_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('position_id')->constrained('positions')->restrictOnDelete();
            $table->string('employment_type');
            $table->decimal('rate', 4, 2)->default(1);
            $table->date('started_at');
            $table->date('ended_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('order_number')->nullable();
            $table->date('order_date')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_status_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('status')->index();
            $table->date('date_from')->index();
            $table->date('date_to')->nullable()->index();
            $table->string('reason')->nullable();
            $table->string('document_number')->nullable();
            $table->date('document_date')->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_status_periods');
        Schema::dropIfExists('employee_assignments');
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropForeign(['head_employee_id']);
        });
        Schema::dropIfExists('employees');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('departments');
    }
};
