<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Три настоящих корпуса колледжа и по одной точке прохода в каждом.
 *
 * Справочник был пуст с самого появления — `buildings` и `access_points` по нулю
 * строк, — и отчёт «Кто в здании» с самого начала складывал всех в группу «точка
 * вне справочника»: связь события со справочником проставляется по названию или
 * коду, а сопоставлять было не с чем.
 *
 * Миграцией, а не сидером: сидер выполняется при установке и больше никогда, а
 * на PROD система уже стоит. `installer/update.sh` запускает миграции и ничего
 * кроме — значит, только так записи и доедут до работающей системы.
 *
 * Общежитие на Серова заведено обычным корпусом: там есть учебные кабинеты, и в
 * отчётах оно должно вести себя как корпус, а не как жильё.
 */
return new class extends Migration
{
    /**
     * Код — то, что наберут в самом сканере при установке, поэтому он короткий и
     * латиницей: `AccessPointResolver` ищет точку по названию **или** коду, без
     * учёта регистра и лишних пробелов, и промахнуться в «Проходная на Голенева,
     * 21» гораздо легче, чем в `GOL21`.
     *
     * Названия точек различаются между корпусами намеренно. Уникальность имени в
     * `access_points` — в пределах корпуса, поэтому три точки с именем
     * «Проходная» завелись бы без единой жалобы, а сопоставление по имени
     * отдавало бы первую попавшуюся из трёх.
     */
    private const ROWS = [
        ['GOL21', 'Учебный корпус на Голенева, 21', 'ул. Голенева, 21', 'Проходная на Голенева, 21', 10],
        ['KRU31', 'Учебный корпус на Крупской, 31', 'ул. Крупской, 31', 'Проходная на Крупской, 31', 20],
        ['SER277', 'Общежитие на Серова, 277', 'ул. Серова, 277', 'Проходная на Серова, 277', 30],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::ROWS as [$code, $name, $address, $pointName, $sortOrder]) {
            // `insertOrIgnore` — это `ON CONFLICT DO NOTHING`. Ловить нарушение
            // уникальности исключением нельзя: на PostgreSQL упавший `INSERT`
            // отравляет транзакцию миграции целиком, и падает она не здесь.
            DB::table('buildings')->insertOrIgnore([
                'name' => $name,
                'code' => $code,
                'address' => $address,
                'is_active' => true,
                'sort_order' => $sortOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $buildingId = DB::table('buildings')->where('code', $code)->value('id');

            // Корпус мог быть заведён руками под другим кодом — тогда точку
            // вешать некуда, и молча создавать второй корпус хуже, чем ничего.
            if ($buildingId === null) {
                continue;
            }

            DB::table('access_points')->insertOrIgnore([
                'building_id' => $buildingId,
                'name' => $pointName,
                'code' => $code,
                'description' => 'Единственная точка прохода корпуса. Код набирается в сканере при установке.',
                'is_active' => true,
                'sort_order' => $sortOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Откат убирает только то, что завела эта миграция, и только пока за записи
     * никто не зацепился: точку с событиями удалять нельзя — связь у событий
     * обнулится, и проходы потеряют корпус, хотя сами останутся.
     */
    public function down(): void
    {
        $codes = array_column(self::ROWS, 0);

        $busy = DB::table('access_points')
            ->whereIn('code', $codes)
            ->whereExists(fn ($query) => $query->selectRaw(1)
                ->from('access_events')
                ->whereColumn('access_events.access_point_id', 'access_points.id'))
            ->pluck('id')
            ->all();

        DB::table('access_points')
            ->whereIn('code', $codes)
            ->whereNotIn('id', $busy)
            ->delete();

        DB::table('buildings')
            ->whereIn('code', $codes)
            ->whereNotExists(fn ($query) => $query->selectRaw(1)
                ->from('access_points')
                ->whereColumn('access_points.building_id', 'buildings.id'))
            ->delete();
    }
};
