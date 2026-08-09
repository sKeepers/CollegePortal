<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\PersonService;
use Database\Seeders\PortalUserSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Сводит исторические служебные учетные записи стенда в один набор.
 *
 * На стенде одна и та же роль жила в двух экземплярах с разными паролями:
 * набор с приставкой UAT и смоук-набор из демо-данных. Плюс несколько учетных
 * записей, заведенных вручную под ту же роль. Команда оставляет по одной на
 * роль, переносит на нее профили и приводит адрес к виду <роль>@local.
 *
 * Выживает не первая попавшаяся строка: у «teacher1.uat» есть Teacher и Person,
 * а у одноименного «teacher» их нет, поэтому выбор идет по связям, а не по
 * возрасту записи. Иначе слияние молча оставило бы учетную запись без профиля,
 * и преподавательский журнал перестал бы открываться.
 */
class MergePortalAccountsCommand extends Command
{
    protected $signature = 'portal:merge-accounts {--apply : Применить изменения; без флага команда только показывает план}';

    protected $description = 'Свести служебные учетные записи стенда в один набор на домене @local.';

    public function __construct(private readonly PersonService $people)
    {
        parent::__construct();
    }

    /**
     * Исторические адреса, которые должны стать одной учетной записью роли.
     *
     * @var array<string, array<int, string>>
     */
    private const LEGACY = [
        'admin' => ['admin.uat@college-portal.local', 'admin@college-portal.local'],
        'director' => ['director.uat@college-portal.local'],
        'deputy' => ['deputy.uat@college-portal.local'],
        'study' => ['study.uat@college-portal.local'],
        'study.records' => ['uch2.check@dev.local'],
        'admission' => ['admission.uat@college-portal.local', 'admission@college-portal.local'],
        'hr' => ['hr.uat@college-portal.local', 'ok@skki.ru'],
        'teacher' => ['teacher1.uat@college-portal.local', 'teacher@college-portal.local'],
        'student' => ['student1.uat@college-portal.local', 'student@college-portal.local'],
        'security' => ['security.uat@college-portal.local'],
    ];

    /** Домены, оставшиеся от прежних написаний служебного адреса. */
    private const LEGACY_DOMAINS = ['college-portal.local', 'accounts.collegeportal.local', 'collegeportal.local'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $roles = Role::query()->pluck('id', 'code');
        $accounts = collect(PortalUserSeeder::accounts())->keyBy('username');

        if (! $apply) {
            $this->warn('Режим показа. Ничего не изменено, для применения нужен флаг --apply.');
        }

        $plan = [];

        foreach (self::LEGACY as $username => $legacyEmails) {
            $account = $accounts->get($username);

            if (! $account) {
                continue;
            }

            $canonical = PortalUserSeeder::email($username);
            $candidates = User::query()
                ->whereIn('email', array_merge($legacyEmails, [$canonical]))
                ->orderBy('id')
                ->get();

            if ($candidates->isEmpty()) {
                continue;
            }

            $survivor = $this->pickSurvivor($candidates);
            $losers = $candidates->reject(fn (User $user): bool => $user->id === $survivor->id);

            $plan[] = [
                'Роль' => $account['role'],
                'Останется' => "#{$survivor->id} {$survivor->email}",
                'Станет' => $canonical,
                'Удалится' => $losers->isEmpty() ? '—' : $losers->map(fn (User $user): string => "#{$user->id} {$user->email}")->implode(', '),
            ];

            if (! $apply) {
                continue;
            }

            DB::transaction(function () use ($survivor, $losers, $canonical, $username, $account, $roles): void {
                $this->moveProfiles($survivor, $losers);

                foreach ($losers as $loser) {
                    $loser->roles()->detach();
                    $loser->delete();
                }

                $survivor->forceFill([
                    'email' => $canonical,
                    'username' => $username,
                    'name' => $this->normalizedName($survivor, $account['name']),
                    'role_id' => $roles[$account['role']] ?? $survivor->role_id,
                ])->save();

                $this->normalizePerson($survivor, $account['name']);

                if ($roleId = $roles[$account['role']] ?? null) {
                    $survivor->roles()->sync([$roleId => ['is_primary' => true]]);
                }
            });
        }

        $this->table(['Роль', 'Останется', 'Станет', 'Удалится'], array_map('array_values', $plan));

        $renamed = $this->renameLeftoverDomains($apply);

        if ($renamed->isNotEmpty()) {
            $this->line('');
            $this->info('Адреса на прежних служебных доменах:');
            $this->table(['Было', 'Стало'], $renamed->all());
        }

        $this->line('');
        $this->info($apply ? 'Учетные записи сведены.' : 'Показан план. Повторите с --apply.');

        return self::SUCCESS;
    }

    /**
     * Выживает учетная запись со связями: сначала профиль студента или
     * преподавателя, затем Person, затем история аудита, и только в последнюю
     * очередь — меньший идентификатор.
     *
     * @param \Illuminate\Support\Collection<int, User> $candidates
     */
    private function pickSurvivor($candidates): User
    {
        // Ключ сортировки собирается одной строкой намеренно: sortBy со списком
        // замыканий считает их компараторами вида ($a, $b), а не вычислителями
        // ключа, и порядок получается произвольным.
        return $candidates
            ->sortBy(function (User $user): string {
                $audit = DB::table('audit_logs')->where('user_id', $user->id)->count();

                return sprintf(
                    '%d%d%09d%09d',
                    $this->hasProfile($user) ? 0 : 1,
                    $user->person_id ? 0 : 1,
                    max(0, 999999999 - $audit),
                    $user->id,
                );
            })
            ->first();
    }

    /**
     * Приставка убирается из имени, а не только из адреса: в списке пользователей
     * и в шапке портала владелец видел «Директор UAT» и «Администратор DEV».
     * Настоящее ФИО не трогаем — под этими же учетными записями работают люди.
     */
    private function normalizedName(User $user, string $canonicalName): string
    {
        return $this->looksLikeStandMarker($user->name) ? $canonicalName : $user->name;
    }

    /**
     * У преподавателя и студента стенда приставка сидит еще и в карточке человека,
     * откуда она зеркалом расходится в Teacher и Student. Запись идет через
     * PersonService — единственное место, где ФИО вообще можно менять.
     */
    private function normalizePerson(User $user, string $canonicalName): void
    {
        $person = $user->person;

        if (! $person || ! $this->looksLikeStandMarker(trim("{$person->last_name} {$person->first_name} {$person->middle_name}"))) {
            return;
        }

        $this->people->updateSharedData($person, [
            'last_name' => $canonicalName,
            'first_name' => 'Стенда',
            'middle_name' => null,
        ]);
    }

    private function looksLikeStandMarker(?string $name): bool
    {
        return $name !== null && preg_match('/\b(UAT|DEV|Проверка)\b/u', $name) === 1;
    }

    private function hasProfile(User $user): bool
    {
        return Student::query()->where('user_id', $user->id)->exists()
            || Teacher::query()->where('user_id', $user->id)->exists();
    }

    /**
     * @param \Illuminate\Support\Collection<int, User> $losers
     */
    private function moveProfiles(User $survivor, $losers): void
    {
        foreach ($losers as $loser) {
            Student::query()->where('user_id', $loser->id)->update(['user_id' => $survivor->id]);
            Teacher::query()->where('user_id', $loser->id)->update(['user_id' => $survivor->id]);

            if (! $survivor->person_id && $loser->person_id) {
                $survivor->forceFill(['person_id' => $loser->person_id, 'person_type' => $loser->person_type])->save();
            }
        }
    }

    /**
     * Учетные записи людей, выданные автоматически, тоже сидят на прежних
     * служебных доменах. Логин у них — телефон, а не адрес, поэтому смена
     * домена вход не ломает, зато в списке пользователей перестают соседствовать
     * три разных написания одного и того же служебного домена.
     *
     * @return \Illuminate\Support\Collection<int, array<int, string>>
     */
    private function renameLeftoverDomains(bool $apply)
    {
        $renamed = collect();
        // Адреса из карты слияния сюда не попадают: в режиме показа они еще
        // существуют, и без этой проверки отчет предлагал бы переименовать строки,
        // которые на самом деле будут удалены или переименованы первым шагом.
        $handled = collect(self::LEGACY)->flatten()->all();

        foreach (self::LEGACY_DOMAINS as $domain) {
            User::query()->where('email', 'like', '%@'.$domain)->whereNotIn('email', $handled)->orderBy('id')->each(function (User $user) use ($domain, $apply, $renamed): void {
                $candidate = substr($user->email, 0, -strlen($domain)).PortalUserSeeder::DOMAIN;

                if (User::query()->where('email', $candidate)->where('id', '!=', $user->id)->exists()) {
                    $this->warn("Адрес {$candidate} уже занят, {$user->email} оставлен без изменений.");

                    return;
                }

                $renamed->push([$user->email, $candidate]);

                if ($apply) {
                    $user->forceFill(['email' => $candidate])->save();
                }
            });
        }

        return $renamed;
    }
}
