<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->string('snils', 32)->nullable()->index()->after('email');
            $table->text('address')->nullable()->after('phone');
            $table->string('passport_series', 20)->nullable()->after('snils');
            $table->string('passport_number', 100)->nullable()->after('passport_series');
            $table->date('passport_issue_date')->nullable()->after('passport_number');
            $table->string('passport_issued_by', 1000)->nullable()->after('passport_issue_date');
            $table->string('enrollment_order_number', 100)->nullable()->after('enrollment_date');
            $table->date('enrollment_order_date')->nullable()->after('enrollment_order_number');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropColumn([
                'snils', 'address', 'passport_series', 'passport_number', 'passport_issue_date', 'passport_issued_by',
                'enrollment_order_number', 'enrollment_order_date',
            ]);
        });
    }
};
