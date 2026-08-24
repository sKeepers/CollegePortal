<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\JournalLesson;
use App\Models\NotificationDelivery;
use App\Models\NotificationSubscription;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationRetryService;
use App\Support\Notifications\MaxNotificationChannel;
use App\Support\Notifications\NotificationChannel;
use App\Support\Notifications\NotificationChannels;
use App\Support\Notifications\NotificationEvents;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Повтор доставки — целиком, от неудачи до второго сообщения.
 *
 * До 24.08.2026 повтора не существовало в работе: `retryDue` был написан и вызывался
 * только из тестов, а команды и строки в планировщике не было. Тесты при этом были
 * зелёные — они проверяли механизм, а не то, что его кто-то заводит. Здесь закрепляется
 * именно недостающая половина: **текст собирается заново и уходит человеку**.
 *
 * И вторая половина того же — **повтор умеет сдаться**. Три способа, и каждый проверен
 * отдельно: попытки кончились, доставка старше суток, новость умерла.
 */
class NotificationRetryRunsTest extends TestCase
{
    use RefreshDatabase;

    private RefusingChannel $channel;

    /**
     * Счётчик дисциплин: код собирается из него, а не из случайного числа.
     *
     * Случайное трёхзначное при нескольких вызовах в одном тесте даёт совпадение
     * заметно чаще, чем кажется — двенадцать вызовов это около 7 % на прогон. Падает
     * при этом не генератор, а то, что рядом, и ищут беду не там.
     */
    private int $subjectNumber = 0;


    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->channel = new RefusingChannel;
        $this->app->instance(NotificationChannels::class, new NotificationChannels([$this->channel]));
    }

    public function test_a_failed_lessons_tomorrow_is_rebuilt_and_delivered(): void
    {
        [$user, $group, $teacher] = $this->subscribedStudent(NotificationEvents::LESSONS_TOMORROW);
        $this->lesson($group, $teacher, now()->addDay());

        $this->channel->accepts = false;
        app(\App\Services\Notifications\LessonsTomorrowNotifier::class)
            ->run(now()->addDay(), MaxNotificationChannel::CODE);

        $this->assertSame(NotificationDelivery::STATUS_FAILED, NotificationDelivery::query()->value('status'));

        $this->channel->accepts = true;
        NotificationDelivery::query()->update(['next_attempt_at' => now()->subMinute()]);

        $result = app(NotificationRetryService::class)->run();

        $this->assertSame(1, $result['sent']);
        $this->assertSame(NotificationDelivery::STATUS_SENT, NotificationDelivery::query()->value('status'));

        // Текст собран заново, а не взят из журнала: в журнале его нет и быть не должно.
        $this->assertStringContainsString('Занятия на '.now()->addDay()->format('d.m'), $this->channel->sent[1][1]);
        $this->assertStringContainsString('Сольфеджио', $this->channel->sent[1][1]);
    }

    /** День прошёл — повторять нечего: расписание на вчера зовёт на занятие, которого нет. */
    public function test_a_past_day_stops_the_retry(): void
    {
        [$user, $group, $teacher] = $this->subscribedStudent(NotificationEvents::LESSONS_TOMORROW);
        $this->lesson($group, $teacher, now()->addDay());

        $this->channel->accepts = false;
        app(\App\Services\Notifications\LessonsTomorrowNotifier::class)
            ->run(now()->addDay(), MaxNotificationChannel::CODE);

        $this->channel->accepts = true;
        $this->travel(3)->days();

        // Возраст доставки здесь ни при чём: он проверяется соседним тестом, а этот —
        // про мёртвую новость. Иначе два способа сдаться накрыли бы друг друга, и тест
        // проходил бы по неверной причине.
        NotificationDelivery::query()->update([
            'created_at' => now()->subMinutes(5),
            'next_attempt_at' => now()->subMinute(),
        ]);

        $result = app(NotificationRetryService::class)->run();

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['exhausted']);
        $this->assertNull(NotificationDelivery::query()->value('next_attempt_at'));
    }

    /** Планировщик мог простоять. Вернувшись, он не должен разгребать очередь недельной давности. */
    public function test_a_delivery_older_than_a_day_is_not_retried(): void
    {
        [$user, $group, $teacher] = $this->subscribedStudent(NotificationEvents::LESSONS_TOMORROW);
        $this->lesson($group, $teacher, now()->addDay());

        $this->channel->accepts = false;
        app(\App\Services\Notifications\LessonsTomorrowNotifier::class)
            ->run(now()->addDay(), MaxNotificationChannel::CODE);

        $this->channel->accepts = true;
        NotificationDelivery::query()->update([
            'next_attempt_at' => now()->subMinute(),
            'created_at' => now()->subHours(NotificationDispatcher::MAX_AGE_HOURS + 1),
        ]);

        $result = app(NotificationRetryService::class)->run();

        $this->assertSame(0, $result['retried']);
        $this->assertSame(0, $result['exhausted']);
    }

    /** Журнал закрыли — напоминание о долге, которого нет, читается как придирка. */
    public function test_a_closed_journal_stops_the_reminder(): void
    {
        $group = Group::create(['name' => 'ВОК-201', 'specialty' => 'Вокальное искусство', 'course' => 2, 'year_start' => 2025]);
        $user = $this->createApiUser();
        $teacher = Teacher::create(['user_id' => $user->id, 'last_name' => 'Власова', 'first_name' => 'Ирина', 'status' => 'active']);
        $this->subscribe($user, NotificationEvents::JOURNAL_UNCLOSED);

        $subject = Subject::create(['name' => 'Сольфеджио', 'code' => 'SOLF001']);
        $lesson = JournalLesson::create([
            'group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'lesson_date' => now()->subDay()->toDateString(),
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'status' => JournalLesson::STATUS_OPENED,
        ]);

        $this->channel->accepts = false;
        app(\App\Services\Notifications\UnclosedJournalNotifier::class)
            ->run(now()->subDay(), MaxNotificationChannel::CODE);

        $this->assertSame(NotificationDelivery::STATUS_FAILED, NotificationDelivery::query()->value('status'));

        $lesson->forceFill(['status' => JournalLesson::STATUS_SIGNED])->save();
        $this->channel->accepts = true;
        NotificationDelivery::query()->update(['next_attempt_at' => now()->subMinute()]);

        $result = app(NotificationRetryService::class)->run();

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['exhausted']);
    }

    /** @return array{0: User, 1: Group, 2: Teacher} */
    private function subscribedStudent(string $event): array
    {
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        $teacher = Teacher::create(['last_name' => 'Власова', 'first_name' => 'Ирина', 'status' => 'active']);
        $user = $this->createApiUser();

        Student::create([
            'user_id' => $user->id,
            'group_id' => $group->id,
            'last_name' => 'Горбачева',
            'first_name' => 'Татьяна',
            'status' => 'active',
        ]);

        $this->subscribe($user, $event);

        return [$user, $group, $teacher];
    }

    private function subscribe(User $user, string $event): void
    {
        NotificationSubscription::create([
            'user_id' => $user->id,
            'event' => $event,
            'channel' => MaxNotificationChannel::CODE,
        ]);

        UserIdentity::create([
            'user_id' => $user->id,
            'provider' => MaxNotificationChannel::CODE,
            'provider_user_id' => (string) $user->id,
            'chat_id' => '327565281',
            'chat_started_at' => now(),
            'linked_at' => now(),
        ]);
    }

    private function lesson(Group $group, Teacher $teacher, $date): ScheduleLesson
    {
        $subject = Subject::create(['name' => 'Сольфеджио', 'code' => 'SOLF'.(++$this->subjectNumber)]);

        return ScheduleLesson::create([
            'group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'lesson_date' => $date->toDateString(),
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'lesson_type' => 'lecture',
        ]);
    }
}

final class RefusingChannel implements NotificationChannel
{
    public bool $accepts = true;

    /** @var list<array{0: string, 1: string}> */
    public array $sent = [];

    public function code(): string
    {
        return MaxNotificationChannel::CODE;
    }

    public function name(): string
    {
        return 'MAX';
    }

    public function send(string $chatId, string $text): bool
    {
        $this->sent[] = [$chatId, $text];

        return $this->accepts;
    }
}
