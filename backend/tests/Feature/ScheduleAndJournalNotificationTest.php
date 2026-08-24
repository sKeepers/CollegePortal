<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\JournalLesson;
use App\Models\NotificationSubscription;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\ScheduleChangeNotifier;
use App\Services\Notifications\UnclosedJournalNotifier;
use App\Support\Notifications\MaxNotificationChannel;
use App\Support\Notifications\NotificationChannel;
use App\Support\Notifications\NotificationChannels;
use App\Support\Notifications\NotificationEvents;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Изменения расписания и незакрытый журнал.
 *
 * Главное, что здесь закрепляется, — **чего не происходит**: опубликованное расписание
 * на семестр не рассылается как «изменение», вчерашние правки не рассылаются вовсе, а
 * отменённое занятие не считается незакрытым. Каждое из трёх легко потерять при следующей
 * правке, и каждое стоило бы бури сообщений или ложной придирки.
 */
class ScheduleAndJournalNotificationTest extends TestCase
{
    use RefreshDatabase;

    private CollectingChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->channel = new CollectingChannel;
        $this->app->instance(NotificationChannels::class, new NotificationChannels([$this->channel]));
    }

    public function test_an_edited_upcoming_lesson_reaches_the_group(): void
    {
        [$user, $group, $teacher] = $this->subscribedStudent(NotificationEvents::SCHEDULE_CHANGED);
        $lesson = $this->lesson($group, $teacher, now()->addDay());
        $this->markEdited($lesson);

        $result = $this->scheduleNotifier()->run(now()->subMinutes(15), MaxNotificationChannel::CODE);

        $this->assertSame(1, $result['sent']);
        $this->assertStringContainsString('Расписание изменилось', $this->channel->sent[0][1]);
    }

    /**
     * Загрузка расписания на семестр создаёт полторы тысячи занятий разом. Если считать
     * появление занятия изменением, первая же загрузка разошлёт уведомление всему колледжу.
     */
    public function test_a_freshly_created_lesson_is_not_a_change(): void
    {
        [$user, $group, $teacher] = $this->subscribedStudent(NotificationEvents::SCHEDULE_CHANGED);
        $this->lesson($group, $teacher, now()->addDay());

        $result = $this->scheduleNotifier()->run(now()->subMinutes(15), MaxNotificationChannel::CODE);

        $this->assertSame(0, $result['changed']);
        $this->assertSame([], $this->channel->sent);
    }

    /** Правка вчерашнего занятия человеку уже не пригодится. */
    public function test_a_change_to_a_past_lesson_is_not_sent(): void
    {
        [$user, $group, $teacher] = $this->subscribedStudent(NotificationEvents::SCHEDULE_CHANGED);
        $lesson = $this->lesson($group, $teacher, now()->subDay());
        $this->markEdited($lesson);

        $result = $this->scheduleNotifier()->run(now()->subMinutes(15), MaxNotificationChannel::CODE);

        $this->assertSame(0, $result['changed']);
    }

    public function test_an_unclosed_lesson_is_reported_to_its_teacher(): void
    {
        [$user, $teacher, $group] = $this->subscribedTeacher();
        $this->journalLesson($group, $teacher, JournalLesson::STATUS_OPENED);

        $result = $this->journalNotifier()->run(now()->subDay(), MaxNotificationChannel::CODE);

        $this->assertSame(1, $result['sent']);
        $this->assertStringContainsString('не закрыт', $this->channel->sent[0][1]);
    }

    /** Отменённое занятие закрывать нечего — оно не состоялось. */
    public function test_a_cancelled_lesson_is_not_a_debt(): void
    {
        [$user, $teacher, $group] = $this->subscribedTeacher();
        $this->journalLesson($group, $teacher, JournalLesson::STATUS_CANCELLED);

        $result = $this->journalNotifier()->run(now()->subDay(), MaxNotificationChannel::CODE);

        $this->assertSame(0, $result['sent']);
        $this->assertSame([], $this->channel->sent);
    }

    public function test_a_signed_journal_produces_no_reminder(): void
    {
        [$user, $teacher, $group] = $this->subscribedTeacher();
        $this->journalLesson($group, $teacher, JournalLesson::STATUS_SIGNED);

        $this->assertSame(0, $this->journalNotifier()->run(now()->subDay(), MaxNotificationChannel::CODE)['sent']);
    }

    /**
     * Свёртка окном ограничивает сообщение, но не их число.
     *
     * Замер на стенде 24.08.2026: восемь окон подряд при непрерывной правке расписания —
     * 4960 сообщений на 620 человек, ровно по восемь каждому. За рабочий день первого
     * сентября это тридцать два сообщения, и отписываются от такого разом.
     */
    public function test_a_second_window_within_the_quiet_hour_holds_the_message(): void
    {
        [$user, $group, $teacher] = $this->subscribedStudent(NotificationEvents::SCHEDULE_CHANGED);
        $lesson = $this->lesson($group, $teacher, now()->addDay());
        $this->markEdited($lesson);

        $first = $this->scheduleNotifier()->run(now()->subMinutes(15), MaxNotificationChannel::CODE);
        $this->assertSame(1, $first['sent']);

        $this->travel(1)->minutes();
        $lesson->forceFill(['updated_at' => now()])->saveQuietly();

        $second = $this->scheduleNotifier()->run(now()->subMinutes(15), MaxNotificationChannel::CODE);

        $this->assertSame(0, $second['sent'], 'Второе сообщение за час уходить не должно.');
        $this->assertSame(1, $second['held']);
        $this->assertCount(1, $this->channel->sent);
    }

    /**
     * Тишина не значит потерю: то, что случилось за час молчания, приходит следующим
     * сообщением. Иначе лекарство от бури оказалось бы хуже самой бури — человек
     * перестал бы узнавать о переносе занятия вовсе.
     */
    public function test_changes_made_during_the_quiet_hour_arrive_afterwards(): void
    {
        [$user, $group, $teacher] = $this->subscribedStudent(NotificationEvents::SCHEDULE_CHANGED);
        $first = $this->lesson($group, $teacher, now()->addDay());
        $this->markEdited($first);

        $this->assertSame(1, $this->scheduleNotifier()->run(now()->subMinutes(15), MaxNotificationChannel::CODE)['sent']);

        // Правка внутри часа тишины: сообщения о ней сейчас не будет.
        $this->travel(10)->minutes();
        $held = $this->lesson($group, $teacher, now()->addDays(2));
        $held->forceFill(['created_at' => now()->subDays(30), 'updated_at' => now()])->saveQuietly();

        $this->assertSame(0, $this->scheduleNotifier()->run(now()->subMinutes(15), MaxNotificationChannel::CODE)['sent']);

        // Час вышел — приходит то, что накопилось.
        $this->travel(55)->minutes();
        $after = $this->scheduleNotifier()->run(now()->subMinutes(15), MaxNotificationChannel::CODE);

        $this->assertSame(1, $after['sent']);
        $this->assertCount(2, $this->channel->sent);
        $this->assertStringContainsString(
            now()->addDays(2)->subMinutes(65)->format('d.m'),
            $this->channel->sent[1][1],
            'Занятие, изменённое во время тишины, обязано попасть в следующее сообщение.',
        );
    }

    /** Час тишины считается по каждому человеку отдельно, а не по рассылке целиком. */
    public function test_the_quiet_hour_is_counted_per_person(): void
    {
        [, $group, $teacher] = $this->subscribedStudent(NotificationEvents::SCHEDULE_CHANGED);
        $lesson = $this->lesson($group, $teacher, now()->addDay());
        $this->markEdited($lesson);

        $this->assertSame(1, $this->scheduleNotifier()->run(now()->subMinutes(15), MaxNotificationChannel::CODE)['sent']);

        // Второй студент той же группы подписался только что: молчать ему не за что.
        $second = $this->createApiUser();
        Student::create([
            'user_id' => $second->id,
            'group_id' => $group->id,
            'last_name' => 'Рябцев',
            'first_name' => 'Сергей',
            'status' => 'active',
        ]);
        $this->subscribe($second, NotificationEvents::SCHEDULE_CHANGED);

        $this->travel(1)->minutes();
        $lesson->forceFill(['updated_at' => now()])->saveQuietly();

        $result = $this->scheduleNotifier()->run(now()->subMinutes(15), MaxNotificationChannel::CODE);

        $this->assertSame(1, $result['sent'], 'Новому подписчику сообщение уходит, старому — нет.');
        $this->assertSame(1, $result['held']);
    }

    private function scheduleNotifier(): ScheduleChangeNotifier
    {
        return new ScheduleChangeNotifier(new NotificationDispatcher(app(NotificationChannels::class)));
    }

    private function journalNotifier(): UnclosedJournalNotifier
    {
        return new UnclosedJournalNotifier(new NotificationDispatcher(app(NotificationChannels::class)));
    }

    /** Правка отличается от создания тем, что `updated_at` заметно позже `created_at`. */
    private function markEdited(ScheduleLesson $lesson): void
    {
        $lesson->forceFill([
            'created_at' => now()->subDays(30),
            'updated_at' => now()->subMinute(),
        ])->saveQuietly();
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

    /** @return array{0: User, 1: Teacher, 2: Group} */
    private function subscribedTeacher(): array
    {
        $group = Group::create(['name' => 'ВОК-201', 'specialty' => 'Вокальное искусство', 'course' => 2, 'year_start' => 2025]);
        $user = $this->createApiUser();
        $teacher = Teacher::create(['user_id' => $user->id, 'last_name' => 'Власова', 'first_name' => 'Ирина', 'status' => 'active']);

        $this->subscribe($user, NotificationEvents::JOURNAL_UNCLOSED);

        return [$user, $teacher, $group];
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
        $subject = Subject::create(['name' => 'Сольфеджио', 'code' => 'SOLF'.random_int(100, 999)]);

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

    private function journalLesson(Group $group, Teacher $teacher, string $status): JournalLesson
    {
        $subject = Subject::create(['name' => 'Гармония', 'code' => 'HARM'.random_int(100, 999)]);

        return JournalLesson::create([
            'group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'lesson_date' => now()->subDay()->toDateString(),
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'status' => $status,
        ]);
    }
}

final class CollectingChannel implements NotificationChannel
{
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

        return true;
    }
}
