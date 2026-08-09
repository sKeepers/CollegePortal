<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Пакет заявлений собирается за конкретный год приёма. Раньше год было негде
 * хранить: `source_entity_type`/`source_entity_id` указывают на сущность, а не
 * на скаляр, и класть год туда значило бы обманывать следующего читателя.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fis_outbound_packages', function (Blueprint $table): void {
            $table->unsignedSmallInteger('admission_year')->nullable()->after('external_campaign_id');
            $table->index(['package_type', 'admission_year']);
        });
    }

    public function down(): void
    {
        Schema::table('fis_outbound_packages', function (Blueprint $table): void {
            $table->dropIndex(['package_type', 'admission_year']);
            $table->dropColumn('admission_year');
        });
    }
};
