<?php

namespace App\Console\Commands;

use App\Models\DigitalIdentity;
use App\Models\Person;
use App\Services\DigitalPassIssueService;
use Illuminate\Console\Command;

/**
 * Выдать пропуск тем, кто заведён раньше и остался без него.
 *
 * Новые карточки получают пропуск сами — при заведении срабатывает наблюдатель.
 * Эта команда закрывает прошлое: людей, заведённых до 21.08.2026, и всякий
 * случай, когда пропуск был отозван вместе с карточкой, а потом человека
 * восстановили.
 *
 * Без `--apply` только показывает, кому чего не хватает.
 */
class IssueMissingDigitalPassesCommand extends Command
{
    protected $signature = 'identity:issue-missing {--apply : Выдать пропуска; без флага команда только считает}';

    protected $description = 'Выдать QR-пропуск людям, у которых действующего пропуска нет.';

    public function __construct(private readonly DigitalPassIssueService $passes)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $candidates = Person::query()
            ->whereDoesntHave('digitalIdentities', fn ($query) => $query->whereIn('status', [
                DigitalIdentity::STATUS_ACTIVE,
                DigitalIdentity::STATUS_SUSPENDED,
            ]))
            ->where(fn ($query) => $query->has('students')->orHas('teachers')->orHas('employees'))
            ->orderBy('last_name')
            ->get(['id', 'last_name', 'first_name', 'middle_name']);

        // Люди без пропуска и без единой живой карточки. Пропуск им привязать не
        // к чему, и это не поломка: карточку могли удалить. Но молчать о них
        // нельзя — иначе «пропуск есть у всех» читается как «и у него тоже».
        $orphans = Person::query()
            ->whereDoesntHave('digitalIdentities', fn ($query) => $query->whereIn('status', [
                DigitalIdentity::STATUS_ACTIVE,
                DigitalIdentity::STATUS_SUSPENDED,
            ]))
            ->whereDoesntHave('students')
            ->whereDoesntHave('teachers')
            ->whereDoesntHave('employees')
            ->count();

        if ($candidates->isEmpty()) {
            $this->info('Пропуск есть у всех, кому его есть к чему привязать.');

            if ($orphans > 0) {
                $this->comment('Людей без пропуска и без единой карточки: '.$orphans.'. Им пропуск не выдаётся: привязать его не к чему.');
            }

            return self::SUCCESS;
        }

        $this->line('Людей без действующего пропуска: '.$candidates->count());

        foreach ($candidates as $person) {
            $name = trim(implode(' ', array_filter([$person->last_name, $person->first_name, $person->middle_name])));

            if (! $this->option('apply')) {
                $this->line('   '.$name);

                continue;
            }

            $issued = $this->passes->ensureForPerson($person->id);
            $this->line(sprintf('   %-40s %s', $name, $issued ? 'пропуск выдан' : 'не к чему привязать'));
        }

        if (! $this->option('apply')) {
            $this->comment('Это только подсчёт. Повторите с --apply, чтобы выдать.');
        }

        return self::SUCCESS;
    }
}
