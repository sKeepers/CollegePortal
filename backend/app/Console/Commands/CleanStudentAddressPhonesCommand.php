<?php

namespace App\Console\Commands;

use App\Services\StudentAddressCleanupService;
use Illuminate\Console\Command;

/**
 * Вынуть телефон из адреса студента и положить в поле телефона.
 *
 * Без `--apply` только считает. Начинать, как и с любой массовой правкой
 * настоящих данных, с `--limit=10`.
 */
class CleanStudentAddressPhonesCommand extends Command
{
    protected $signature = 'students:clean-address-phones
        {--limit=0 : Взять только первые N карточек с телефоном в адресе — проба}
        {--apply : Записать изменения; без флага команда только считает}';

    protected $description = 'Перенести телефон из строки адреса студента в поле телефона.';

    public function __construct(private readonly StudentAddressCleanupService $cleanup)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $apply = (bool) $this->option('apply');

        $summary = $this->cleanup->clean($apply, $limit > 0 ? $limit : null);

        $this->info($apply ? 'Разобрано:' : 'Холостой проход, разобрало бы:');
        $this->table(['Что', 'Карточек'], [
            ['просмотрено', $summary['scanned']],
            ['телефон найден в адресе', $summary['phone_in_address']],
            ['телефон перенесён в поле', $summary['phone_written']],
            ['адрес подрезан', $summary['address_trimmed']],
            ['оставлено человеку', $summary['skipped']],
        ]);

        if ($summary['issues'] !== []) {
            $reasons = [];
            foreach ($summary['issues'] as $issue) {
                $reasons[$issue['category']] = ($reasons[$issue['category']] ?? 0) + 1;
            }
            $this->warn('Не тронуто, разбирает человек:');
            $this->table(['Причина', 'Карточек'], array_map(
                fn (string $reason, int $count): array => [$reason, $count],
                array_keys($reasons),
                array_values($reasons),
            ));
            $this->line('Номера карточек: '.implode(', ', array_column($summary['issues'], 'student_id')));
        }

        if (! $apply) {
            $this->comment('Это был холостой проход. Записать — тот же вызов с --apply.');
        }

        return self::SUCCESS;
    }
}
