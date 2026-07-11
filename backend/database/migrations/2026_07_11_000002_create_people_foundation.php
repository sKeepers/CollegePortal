<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table): void {
            $table->id();
            $table->string('last_name')->index();
            $table->string('first_name')->index();
            $table->string('middle_name')->nullable();
            $table->date('birth_date')->nullable()->index();
            $table->string('gender', 32)->nullable();
            $table->string('citizenship', 100)->nullable();
            $table->string('phone', 50)->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('photo_path')->nullable();
            $table->string('snils', 32)->nullable()->index();
            $table->string('inn', 32)->nullable()->index();
            $table->string('status', 40)->default('active')->index();
            $table->timestamps();

            $table->index(['last_name', 'first_name', 'middle_name', 'birth_date']);
        });

        $this->addPersonColumn('students');
        $this->addPersonColumn('teachers');
        $this->addPersonColumn('applicant_applications');
        $this->addPersonColumn('graduates');
        $this->addPersonColumn('digital_identities');
    }

    public function down(): void
    {
        $this->dropPersonColumn('digital_identities');
        $this->dropPersonColumn('graduates');
        $this->dropPersonColumn('applicant_applications');
        $this->dropPersonColumn('teachers');
        $this->dropPersonColumn('students');

        Schema::dropIfExists('people');
    }

    private function addPersonColumn(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'person_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->foreignId('person_id')->nullable()->after('id')->constrained('people')->nullOnDelete();
        });
    }

    private function dropPersonColumn(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'person_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            $table->dropConstrainedForeignId('person_id');
        });
    }
};
