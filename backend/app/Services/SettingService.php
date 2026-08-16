<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SettingService
{
    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function definitions(): array
    {
        return [
            'general' => [
                'college_full_name' => ['value' => 'Государственное бюджетное профессиональное образовательное учреждение Ставропольского края «Ставропольский краевой колледж искусств»', 'type' => 'string', 'is_public' => true, 'label' => 'Полное название колледжа', 'description' => 'Используется в шапках, отчетах и публичных разделах.'],
                'college_short_name' => ['value' => 'СККИ', 'type' => 'string', 'is_public' => true, 'label' => 'Краткое название колледжа', 'description' => 'Короткое имя для интерфейса и мобильного кабинета.'],
                'college_address' => ['value' => 'г. Ставрополь', 'type' => 'string', 'is_public' => true, 'label' => 'Адрес', 'description' => 'Почтовый или фактический адрес колледжа.'],
                'college_phone' => ['value' => '', 'type' => 'string', 'is_public' => true, 'label' => 'Телефон', 'description' => 'Контактный телефон.'],
                'college_email' => ['value' => '', 'type' => 'email', 'is_public' => true, 'label' => 'Email', 'description' => 'Официальная электронная почта.'],
                'college_website' => ['value' => '', 'type' => 'url', 'is_public' => true, 'label' => 'Сайт', 'description' => 'Официальный сайт колледжа.'],
            ],
            'academic' => [
                'current_academic_year' => ['value' => '2026/2027', 'type' => 'string', 'is_public' => false, 'label' => 'Текущий учебный год', 'description' => 'Используется как значение по умолчанию в учебных модулях.'],
                'current_semester' => ['value' => 1, 'type' => 'integer', 'is_public' => false, 'label' => 'Текущий семестр', 'description' => 'Номер активного семестра.'],
                'lesson_duration_minutes' => ['value' => 45, 'type' => 'integer', 'is_public' => false, 'label' => 'Длительность занятия, минут', 'description' => 'Базовая длительность одного занятия.'],
                'default_week_start' => ['value' => 'monday', 'type' => 'string', 'is_public' => false, 'label' => 'Первый день недели', 'description' => 'Для расписания и отчетов.'],
            ],
            'attendance' => [
                'teacher_late_threshold_minutes' => ['value' => 5, 'type' => 'integer', 'is_public' => false, 'label' => 'Порог опоздания преподавателя, минут', 'description' => 'Используется в аналитике посещаемости и блоке требует внимания.'],
                'student_late_threshold_minutes' => ['value' => 10, 'type' => 'integer', 'is_public' => false, 'label' => 'Порог опоздания студента, минут', 'description' => 'Используется в аналитике посещаемости и блоке требует внимания.'],
                'early_leave_threshold_minutes' => ['value' => 10, 'type' => 'integer', 'is_public' => false, 'label' => 'Порог раннего ухода, минут', 'description' => 'Если последний выход раньше планового окончания на это число минут, фиксируется ранний уход.'],
                'max_open_session_hours' => ['value' => 16, 'type' => 'integer', 'is_public' => false, 'label' => 'Максимум открытой сессии, часов', 'description' => 'Используется для проверки незакрытого входа без выхода.'],
            ],
            'admissions' => [
                'current_admission_campaign' => ['value' => 'Прием 2026', 'type' => 'string', 'is_public' => false, 'label' => 'Текущая приемная кампания', 'description' => 'Название активной приемной кампании.'],
                'max_applications_per_applicant' => ['value' => 3, 'type' => 'integer', 'is_public' => false, 'label' => 'Максимум заявлений на абитуриента', 'description' => 'Ограничение для будущих проверок приема.'],
                'max_choices_per_application' => ['value' => 5, 'type' => 'integer', 'is_public' => false, 'label' => 'Максимум выбранных программ в заявлении', 'description' => 'Ограничение BACK-004 для выбранных образовательных программ одного заявления.'],
            ],
            'graduation' => [
                'current_graduation_year' => ['value' => 2027, 'type' => 'integer', 'is_public' => false, 'label' => 'Текущий год выпуска', 'description' => 'Используется в выпускниках, дипломах и ФРДО.'],
                'diploma_series_default' => ['value' => '', 'type' => 'string', 'is_public' => false, 'label' => 'Серия диплома по умолчанию', 'description' => 'Не является секретом, но требует проверки перед production.'],
            ],
            'identity' => [
                'digital_pass_default_days' => ['value' => 365, 'type' => 'integer', 'is_public' => false, 'label' => 'Срок QR-пропуска, дней', 'description' => 'Срок действия цифрового пропуска по умолчанию.'],
                'duplicate_scan_window_seconds' => ['value' => 2, 'type' => 'integer', 'is_public' => true, 'label' => 'Окно защиты от дубля скана, секунд', 'description' => 'Повторный скан одного QR в этом окне не создает новое событие.'],
            ],
            'hr' => [
                'weekday_workday_start' => ['value' => '09:00', 'type' => 'time', 'is_public' => false, 'label' => 'Начало графика 5/2', 'description' => 'Время начала для новых назначений графика 5/2. Не изменяет уже назначенный сотруднику код графика.'],
                'weekday_workday_end' => ['value' => '18:00', 'type' => 'time', 'is_public' => false, 'label' => 'Окончание графика 5/2', 'description' => 'Время окончания для новых назначений графика 5/2.'],
                // `employee_late_threshold_minutes` убран 17.08.2026. Его не
                // читал никто: разбора посещаемости сотрудников не существует
                // вовсе — `AttendanceAnalysisService` знает только студента и
                // преподавателя, отчёт проходной считает опоздания тоже лишь по
                // этим двум. Настройка обещала то, чего нет, и её собственное
                // описание это признавало словом «будет». Переписать подпись на
                // правду было бы хуже всего: расхождение осталось бы, но
                // выглядело бы согласованным. Понадобится — заведём вместе с
                // самим разбором, одной задачей.
            ],
            'integrations' => [
                'frdo_mode' => ['value' => 'preparation', 'type' => 'string', 'is_public' => false, 'label' => 'Режим ФРДО', 'description' => 'Пока используется подготовка данных без реальной отправки.'],
                'fis_mode' => ['value' => 'preparation', 'type' => 'string', 'is_public' => false, 'label' => 'Режим ФИС', 'description' => 'Пока используется подготовка данных без реальной отправки.'],
            ],
            'branding' => [
                'logo_path' => ['value' => '/brand/logo-skki-bw.jpg', 'type' => 'path', 'is_public' => true, 'label' => 'Путь к логотипу', 'description' => 'Публичный путь к логотипу интерфейса.'],
                'favicon_path' => ['value' => '/favicon.ico', 'type' => 'path', 'is_public' => true, 'label' => 'Путь к favicon', 'description' => 'Публичный путь к favicon.'],
                'primary_color' => ['value' => '#2563eb', 'type' => 'color', 'is_public' => true, 'label' => 'Основной цвет', 'description' => 'Базовый цвет интерфейса CollegePortal.'],
            ],
        ];
    }

    /**
     * Досоздать недостающие настройки — одним чтением и одной вставкой.
     *
     * Раньше здесь шёл `firstOrCreate` на каждое определение, то есть `SELECT`
     * на каждую из трёх десятков настроек **при каждом обращении к любой из
     * них**. Замер на стенде 16.08.2026: экран посещаемости — 29 запросов к
     * `settings` из 47, панель директора — 58 из 159. Ни одного из них не было
     * видно снаружи: они выглядели как «ну, много мелких запросов».
     *
     * `insertOrIgnore` — это `ON CONFLICT DO NOTHING`: ловить нарушение
     * уникальности исключением нельзя, на PostgreSQL упавшая вставка отравляет
     * транзакцию целиком, а вызывают это и из транзакций тоже.
     */
    public static function ensureDefaults(): void
    {
        $existing = Setting::query()
            ->get(['group', 'key'])
            ->map(fn (Setting $setting): string => $setting->group.'|'.$setting->key)
            ->flip();

        $now = now();
        $missing = [];

        foreach (self::definitions() as $group => $settings) {
            foreach ($settings as $key => $definition) {
                if ($existing->has($group.'|'.$key)) {
                    continue;
                }

                $missing[] = [
                    'group' => $group,
                    'key' => $key,
                    // Приведение `array` пишет значение как JSON, а вставка идёт
                    // мимо модели — кодируем сами и теми же флагами, что Eloquent.
                    'value' => json_encode($definition['value']),
                    'type' => $definition['type'],
                    'is_public' => $definition['is_public'],
                    'description' => $definition['description'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($missing !== []) {
            Setting::query()->insertOrIgnore($missing);
        }
    }

    /**
     * @return Collection<int, Setting>
     */
    public static function all(): Collection
    {
        self::ensureDefaults();

        return Setting::query()
            ->orderBy('group')
            ->orderBy('key')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public static function groupedPayload(): array
    {
        $definitions = self::definitions();

        $settings = self::all()->keyBy(fn (Setting $setting) => $setting->group.'.'.$setting->key);
        $payload = [];

        foreach ($definitions as $group => $items) {
            foreach ($items as $key => $definition) {
                $setting = $settings->get($group.'.'.$key);

                if ($setting === null) {
                    continue;
                }

                $payload[$group][] = [
                    'id' => $setting->id,
                    'group' => $setting->group,
                    'key' => $setting->key,
                    'value' => $setting->value,
                    'default_value' => $definition['value'] ?? null,
                    'type' => $setting->type,
                    'is_public' => $setting->is_public,
                    'label' => $definition['label'] ?? $setting->key,
                    'description' => $setting->description,
                ];
            }
        }

        return $payload;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function publicSettings(): array
    {
        self::ensureDefaults();

        return Setting::query()
            ->where('is_public', true)
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->groupBy('group')
            ->map(fn (Collection $settings) => $settings->mapWithKeys(fn (Setting $setting) => [$setting->key => $setting->value])->toArray())
            ->toArray();
    }

    /**
     * Значение одной настройки.
     *
     * Умолчания досоздаются, только если спрошенной строки нет: настройка,
     * которая уже лежит в базе, стоит один запрос, а не проверку всего
     * каталога. Раньше каталог перепроверялся на каждое чтение, и один
     * `value()` обходился в три десятка запросов.
     */
    public static function value(string $group, string $key, mixed $fallback = null): mixed
    {
        $setting = Setting::query()->where('group', $group)->where('key', $key)->first();

        if ($setting !== null) {
            return $setting->value ?? $fallback;
        }

        self::ensureDefaults();

        // Через модель, а не `->value('value')`: приведение `array` разбирает
        // JSON, а построитель отдал бы строку.
        return Setting::query()->where('group', $group)->where('key', $key)->first()?->value ?? $fallback;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public static function updateMany(array $items): Collection
    {
        self::ensureDefaults();
        $definitions = self::definitions();

        return DB::transaction(function () use ($items, $definitions): Collection {
            $updated = collect();

            foreach ($items as $item) {
                $group = (string) ($item['group'] ?? '');
                $key = (string) ($item['key'] ?? '');

                if (! isset($definitions[$group][$key])) {
                    throw new InvalidArgumentException("Unknown setting {$group}.{$key}");
                }

                $definition = $definitions[$group][$key];
                $setting = Setting::query()->where('group', $group)->where('key', $key)->firstOrFail();
                $setting->update([
                    'value' => self::normalizeValue($item['value'] ?? null, $definition['type']),
                    'type' => $definition['type'],
                    'is_public' => $definition['is_public'],
                    'description' => $definition['description'] ?? null,
                ]);
                $updated->push($setting->refresh());
            }

            return $updated;
        });
    }

    public static function resetToDefaults(): Collection
    {
        self::ensureDefaults();
        $items = [];

        foreach (self::definitions() as $group => $settings) {
            foreach ($settings as $key => $definition) {
                $items[] = ['group' => $group, 'key' => $key, 'value' => $definition['value']];
            }
        }

        return self::updateMany($items);
    }

    public static function normalizeValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'integer' => $value === null || $value === '' ? null : (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            default => $value === null ? '' : (string) $value,
        };
    }
}
