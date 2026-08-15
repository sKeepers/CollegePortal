<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\NotificationLinkCode;
use App\Models\NotificationSubscription;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Notifications\LessonsTomorrowNotifier;
use App\Services\Notifications\MaxLinkService;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\Notifications\MaxNotificationChannel;
use App\Support\Notifications\NotificationChannel;
use App\Support\Notifications\NotificationChannels;
use App\Support\Notifications\NotificationEvents;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Первое настоящее событие уведомлений и привязка, без которой оно никуда не уйдёт.
 *
 * Канал подменён: настоящая отправка проверена живьём со стенда 11.08.2026, а здесь
 * закрепляется то, что от неё не зависит, — кому событие адресуется, что попадает
 * в текст и почему повторный запуск ничего не дублирует.
 */
class LessonsTomorrowNotificationTest extends TestCase
{
    use RefreshDatabase;

    private RecordingChannel $channel;

    private ?Teacher $teacher = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->channel = new RecordingChannel;
        $this->app->instance(NotificationChannels::class, new NotificationChannels([$this->channel]));
    }

    public function test_a_student_gets_the_schedule_of_their_group(): void
    {
        [$user, $group] = $this->subscribedStudent();
        $this->lesson($group, '09:00', 'Сольфеджио');
        $this->lesson($group, '10:40', 'Гармония');

        $result = $this->notifier()->run(now()->addDay(), MaxNotificationChannel::CODE);

        $this->assertSame(1, $result['sent']);
        $this->assertStringContainsString('Сольфеджио', $this->channel->sent[0][1]);
        $this->assertStringContainsString('Гармония', $this->channel->sent[0][1]);
    }

    /** Расписание чужой группы человеку не уходит: адресация идёт по профилю, а не по роли. */
    public function test_the_schedule_of_another_group_is_not_sent(): void
    {
        [$user] = $this->subscribedStudent();
        $other = Group::create(['name' => 'ВОК-201', 'specialty' => 'Вокальное искусство', 'course' => 2, 'year_start' => 2025]);
        $this->lesson($other, '09:00', 'Чужое занятие');

        $result = $this->notifier()->run(now()->addDay(), MaxNotificationChannel::CODE);

        $this->assertSame(0, $result['sent']);
        $this->assertSame([], $this->channel->sent);
    }

    /**
     * Пустой день пропускается молча. «Завтра занятий нет» человек прочитает один раз,
     * а получать это каждое воскресенье не захочет.
     */
    public function test_a_day_without_lessons_produces_no_message(): void
    {
        $this->subscribedStudent();

        $result = $this->notifier()->run(now()->addDay(), MaxNotificationChannel::CODE);

        $this->assertSame(0, $result['sent']);
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_running_twice_does_not_send_twice(): void
    {
        [$user, $group] = $this->subscribedStudent();
        $this->lesson($group, '09:00', 'Сольфеджио');
        $date = now()->addDay();

        $this->notifier()->run($date, MaxNotificationChannel::CODE);
        $this->notifier()->run($date, MaxNotificationChannel::CODE);

        $this->assertCount(1, $this->channel->sent);
    }

    /** Код выдаётся вошедшему — этим и обеспечивается, что человек привязывает себя. */
    public function test_the_link_code_is_issued_to_the_signed_in_person(): void
    {
        config()->set('services.max.bot_username', 'skki_portal_bot');
        $user = $this->createApiUser();

        $response = $this->withApiAuth($user)->postJson('/api/account/notifications/link-code');

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.code'));
        $this->assertDatabaseHas('notification_link_codes', ['user_id' => $user->id]);
    }

    /**
     * Код, присланный боту, связывает диалог с учётной записью. Само нажатие «Старт»
     * этого не делает: бот видит человека в мессенджере, но не знает, кто это в портале.
     */
    public function test_a_code_sent_to_the_bot_links_the_dialog(): void
    {
        $user = $this->createApiUser();
        $code = app(MaxLinkService::class)->issueCode($user);

        Http::fake(['*/updates*' => Http::response([
            'updates' => [[
                'update_type' => 'message_created',
                'message' => [
                    'sender' => ['user_id' => 98416724],
                    'recipient' => ['chat_id' => 327565281],
                    'body' => ['text' => $code->code],
                ],
            ]],
            'marker' => 15302,
        ])]);

        $linked = app(MaxLinkService::class)->pullUpdates();

        $this->assertSame(1, $linked);
        $this->assertDatabaseHas('user_identities', [
            'user_id' => $user->id,
            'provider' => MaxNotificationChannel::CODE,
            'chat_id' => '327565281',
        ]);
        $this->assertNotNull(NotificationLinkCode::find($code->id)->used_at);
    }

    public function test_a_used_code_does_not_link_a_second_dialog(): void
    {
        $user = $this->createApiUser();
        $code = app(MaxLinkService::class)->issueCode($user);
        $code->forceFill(['used_at' => now()])->save();

        Http::fake(['*/updates*' => Http::response([
            'updates' => [[
                'message' => [
                    'sender' => ['user_id' => 1],
                    'recipient' => ['chat_id' => 2],
                    'body' => ['text' => $code->code],
                ],
            ]],
            'marker' => 1,
        ])]);

        $this->assertSame(0, app(MaxLinkService::class)->pullUpdates());
        $this->assertDatabaseCount('user_identities', 0);
    }

    private function notifier(): LessonsTomorrowNotifier
    {
        return new LessonsTomorrowNotifier(new NotificationDispatcher(app(NotificationChannels::class)));
    }

    /** @return array{0: User, 1: Group} */
    private function subscribedStudent(): array
    {
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        $user = $this->createApiUser();

        Student::create([
            'user_id' => $user->id,
            'group_id' => $group->id,
            'last_name' => 'Горбачева',
            'first_name' => 'Татьяна',
            'status' => 'active',
        ]);

        NotificationSubscription::create([
            'user_id' => $user->id,
            'event' => NotificationEvents::LESSONS_TOMORROW,
            'channel' => MaxNotificationChannel::CODE,
        ]);

        UserIdentity::create([
            'user_id' => $user->id,
            'provider' => MaxNotificationChannel::CODE,
            'provider_user_id' => '98416724',
            'chat_id' => '327565281',
            'chat_started_at' => now(),
            'linked_at' => now(),
        ]);

        return [$user, $group];
    }

    private function lesson(Group $group, string $startsAt, string $subjectName): ScheduleLesson
    {
        $subject = Subject::create(['name' => $subjectName, 'code' => mb_substr($subjectName, 0, 4).random_int(100, 999)]);

        // Преподаватель у занятия обязателен на уровне схемы: без него вставка падает
        // на NOT NULL, а не на проверке приложения.
        $this->teacher ??= Teacher::create([
            'last_name' => 'Власова',
            'first_name' => 'Ирина',
            'status' => 'active',
        ]);

        return ScheduleLesson::create([
            'group_id' => $group->id,
            'subject_id' => $subject->id,
            'teacher_id' => $this->teacher->id,
            'lesson_date' => now()->addDay()->toDateString(),
            'starts_at' => $startsAt,
            'ends_at' => '11:00',
            'lesson_type' => 'lecture',
        ]);
    }
}

final class RecordingChannel implements NotificationChannel
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
