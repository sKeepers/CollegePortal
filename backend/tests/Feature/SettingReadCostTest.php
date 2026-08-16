<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Чтение одной настройки стоит один запрос.
 *
 * До 16.08.2026 `SettingService::value()` при каждом обращении перепроверял весь
 * каталог умолчаний — `firstOrCreate` на каждое определение, то есть `SELECT` на
 * каждую из трёх десятков настроек ради одной спрошенной. Снаружи это выглядело
 * как «много мелких запросов»: замер на стенде — экран посещаемости 29 запросов
 * к `settings` из 47, панель директора 58 из 159.
 *
 * Тест считает запросы, а не время: время в CI и на стенде разное.
 */
class SettingReadCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_reading_a_known_setting_costs_one_query(): void
    {
        // Каталог уже на месте — дальше проверять его незачем.
        SettingService::ensureDefaults();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $value = SettingService::value('academic', 'current_academic_year', 'нет');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame('2026/2027', $value, 'Значение обязано доехать, иначе считаем стоимость пустоты');
        $this->assertSame(1, $queries, "Чтение настройки стоило {$queries} запросов");
    }

    /**
     * Умолчания всё ещё досоздаются — просто не на каждом чтении, а когда
     * спрошенной строки действительно нет.
     */
    public function test_a_missing_setting_is_created_from_the_catalogue(): void
    {
        Setting::query()->delete();

        $this->assertSame('2026/2027', SettingService::value('academic', 'current_academic_year', 'нет'));
        $this->assertDatabaseHas('settings', ['group' => 'academic', 'key' => 'current_academic_year']);

        // И весь каталог тоже: `ensureDefaults` восстанавливает недостающее
        // целиком, а не только спрошенное.
        $this->assertGreaterThan(10, Setting::query()->count());
    }

    /**
     * Значение, изменённое человеком, не должно быть перетёрто умолчанием при
     * следующем досоздании: `insertOrIgnore` вставляет только недостающее.
     */
    public function test_ensure_defaults_does_not_overwrite_a_changed_value(): void
    {
        SettingService::ensureDefaults();
        Setting::query()->where('group', 'academic')->where('key', 'current_academic_year')
            ->update(['value' => json_encode('2030/2031')]);

        SettingService::ensureDefaults();

        $this->assertSame('2030/2031', SettingService::value('academic', 'current_academic_year'));
    }

    /**
     * Досоздание каталога с нуля — это два запроса, а не по одному на настройку.
     */
    public function test_filling_the_catalogue_does_not_query_per_setting(): void
    {
        Setting::query()->delete();

        DB::flushQueryLog();
        DB::enableQueryLog();
        SettingService::ensureDefaults();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(10, Setting::query()->count(), 'Каталог обязан наполниться, иначе считаем стоимость пустоты');
        $this->assertLessThanOrEqual(3, $queries, "Наполнение каталога стоило {$queries} запросов");
    }
}
