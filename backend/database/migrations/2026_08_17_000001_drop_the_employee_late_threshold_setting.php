<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Убрать настройку «Порог опоздания сотрудника».
 *
 * Её не читал никто: разбора посещаемости сотрудников в портале нет —
 * `AttendanceAnalysisService` знает студента и преподавателя, отчёт проходной
 * считает опоздания тоже лишь по этим двум. Настройка обещала то, чего не
 * существует, и её собственное описание это признавало словом «будет».
 *
 * Миграцией, а не только правкой каталога: `RoleSeeder` и умолчания настроек
 * выполняются при установке и больше никогда, а система уже стоит — на PROD с
 * 17.08.2026 работают живые люди. Из каталога определение убрано тем же
 * коммитом, иначе `ensureDefaults` завёл бы строку заново.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->where('group', 'attendance')
            ->where('key', 'employee_late_threshold_minutes')
            ->delete();
    }

    /**
     * Откат возвращает строку с прежним значением по умолчанию. Что в ней стояло
     * до удаления, миграция не знает и знать не может — но и не важно: значение
     * ни на что не влияло, в этом и была причина убрать.
     */
    public function down(): void
    {
        $now = now();

        DB::table('settings')->insertOrIgnore([
            'group' => 'attendance',
            'key' => 'employee_late_threshold_minutes',
            'value' => json_encode(10),
            'type' => 'integer',
            'is_public' => false,
            'description' => 'Будет применяться к персональной статистике проходов сотрудников после ее включения.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
