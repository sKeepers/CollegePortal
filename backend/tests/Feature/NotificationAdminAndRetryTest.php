<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NotificationDelivery;
use App\Models\NotificationSubscription;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\Notifications\MaxNotificationChannel;
use App\Support\Notifications\NotificationChannel;
use App\Support\Notifications\NotificationChannels;
use App\Support\Notifications\NotificationEvents;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Доделка `NOTIFY-001`: исполнитель распоряжения директора, повторы и видимость.
 *
 * Всё три следуют из решений владельца, а не из вкуса. Родитель получает уведомления
 * без согласия студента, студент отключить их не может, менять порядок может только
 * распоряжение директора — значит нужен тот, кто это распоряжение исполнит **в портале
 * и со следом**, и нужно, чтобы студент видел, что о нём пишут.
 */
class NotificationAdminAndRetryTest extends TestCase
{
    use RefreshDatabase;

    private FlakyChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->channel = new FlakyChannel;
        $this->app->instance(NotificationChannels::class, new NotificationChannels([$this->channel]));
    }

    public function test_an_administrator_removes_a_subscription_and_the_removal_leaves_a_trace(): void
    {
        $person = $this->subscribed();
        $subscription = NotificationSubscription::query()->where('user_id', $person->id)->firstOrFail();
        $admin = $this->createApiUser(roleCode: 'admin');

        $this->withApiAuth($admin)
            ->deleteJson("/api/admin/users/{$person->id}/notifications/{$subscription->id}")
            ->assertOk();

        $this->assertDatabaseCount('notification_subscriptions', 0);

        // Право снимать чужое без следа превратило бы раздел в способ тихо отрезать
        // человека от того, что ему полагается знать.
        $this->assertDatabaseHas('audit_logs', ['action' => 'notification_unsubscribed_by_administrator']);
        $this->assertSame($admin->id, AuditLog::query()->where('action', 'notification_unsubscribed_by_administrator')->value('user_id'));
    }

    /** Подписка другого человека через чужой идентификатор не снимается. */
    public function test_a_subscription_of_another_person_is_not_found(): void
    {
        $person = $this->subscribed();
        $stranger = $this->createApiUser();
        $subscription = NotificationSubscription::query()->where('user_id', $person->id)->firstOrFail();

        $this->withApiAuth($this->createApiUser(roleCode: 'admin'))
            ->deleteJson("/api/admin/users/{$stranger->id}/notifications/{$subscription->id}")
            ->assertNotFound();

        $this->assertDatabaseCount('notification_subscriptions', 1);
    }

    /**
     * Отключить уведомления о себе человек не может — так решил владелец, — но видеть,
     * что они кому-то идут, обязан: скрытая рассылка обнаруживается в худший момент.
     */
    public function test_a_person_sees_who_receives_notifications_about_them(): void
    {
        $student = $this->subscribed();
        $parent = $this->createApiUser();
        $parent->forceFill(['name' => 'Родитель'])->save();

        NotificationSubscription::create([
            'user_id' => $parent->id,
            'subject_user_id' => $student->id,
            'event' => NotificationEvents::GRADES_DAILY,
            'channel' => MaxNotificationChannel::CODE,
        ]);

        $response = $this->withApiAuth($student)->getJson('/api/account/notifications');

        $response->assertOk();
        $response->assertJsonPath('data.watchers.0.name', 'Родитель');
        $response->assertJsonPath('data.watchers.0.event', NotificationEvents::GRADES_DAILY);
    }

    /** Собственная подписка чужой не считается: иначе человек «следил» бы за собой. */
    public function test_an_own_subscription_is_not_listed_as_a_watcher(): void
    {
        $person = $this->subscribed();

        $this->withApiAuth($person)->getJson('/api/account/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data.watchers');
    }

    public function test_a_refused_delivery_is_scheduled_for_another_attempt(): void
    {
        $person = $this->subscribedWithChat();
        $this->channel->accepts = false;

        $this->dispatcher()->send($person, NotificationEvents::LESSONS_TOMORROW, 'ключ', 'текст', MaxNotificationChannel::CODE);

        $delivery = NotificationDelivery::query()->firstOrFail();
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $delivery->status);
        $this->assertNotNull($delivery->next_attempt_at);
        $this->assertSame(1, $delivery->attempts);
    }

    public function test_a_due_delivery_is_retried_and_succeeds(): void
    {
        $person = $this->subscribedWithChat();
        $this->channel->accepts = false;
        $this->dispatcher()->send($person, NotificationEvents::LESSONS_TOMORROW, 'ключ', 'текст', MaxNotificationChannel::CODE);

        NotificationDelivery::query()->update(['next_attempt_at' => now()->subMinute()]);
        $this->channel->accepts = true;

        $result = $this->dispatcher()->retryDue(fn () => 'текст заново');

        $this->assertSame(1, $result['sent']);
        $this->assertSame(NotificationDelivery::STATUS_SENT, NotificationDelivery::query()->value('status'));
        $this->assertSame('текст заново', $this->channel->sent[1][1]);
    }

    /** После трёх попыток портал перестаёт стучаться: заблокированный бот не разблокируется сам. */
    public function test_attempts_stop_after_the_third(): void
    {
        $person = $this->subscribedWithChat();
        $this->channel->accepts = false;
        $this->dispatcher()->send($person, NotificationEvents::LESSONS_TOMORROW, 'ключ', 'текст', MaxNotificationChannel::CODE);

        for ($i = 0; $i < 4; $i++) {
            NotificationDelivery::query()->whereNotNull('next_attempt_at')->update(['next_attempt_at' => now()->subMinute()]);
            $this->dispatcher()->retryDue(fn () => 'текст');
        }

        $delivery = NotificationDelivery::query()->firstOrFail();
        $this->assertSame(NotificationDispatcher::MAX_ATTEMPTS, $delivery->attempts);
        $this->assertNull($delivery->next_attempt_at);
    }

    /** Событие устарело — повторять нечего, но строка остаётся: по ней видно, что не дошло. */
    public function test_a_stale_event_stops_being_retried(): void
    {
        $person = $this->subscribedWithChat();
        $this->channel->accepts = false;
        $this->dispatcher()->send($person, NotificationEvents::LESSONS_TOMORROW, 'ключ', 'текст', MaxNotificationChannel::CODE);
        NotificationDelivery::query()->update(['next_attempt_at' => now()->subMinute()]);

        $result = $this->dispatcher()->retryDue(fn () => null);

        $this->assertSame(1, $result['exhausted']);
        $this->assertNull(NotificationDelivery::query()->value('next_attempt_at'));
        $this->assertDatabaseCount('notification_deliveries', 1);
    }

    private function dispatcher(): NotificationDispatcher
    {
        return new NotificationDispatcher(app(NotificationChannels::class));
    }

    private function subscribed(): User
    {
        $user = $this->createApiUser();

        NotificationSubscription::create([
            'user_id' => $user->id,
            'subject_user_id' => $user->id,
            'event' => NotificationEvents::LESSONS_TOMORROW,
            'channel' => MaxNotificationChannel::CODE,
        ]);

        return $user;
    }

    private function subscribedWithChat(): User
    {
        $user = $this->subscribed();

        UserIdentity::create([
            'user_id' => $user->id,
            'provider' => MaxNotificationChannel::CODE,
            'provider_user_id' => (string) $user->id,
            'chat_id' => '327565281',
            'chat_started_at' => now(),
            'linked_at' => now(),
        ]);

        return $user;
    }
}

final class FlakyChannel implements NotificationChannel
{
    /** @var list<array{0: string, 1: string}> */
    public array $sent = [];

    public bool $accepts = true;

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
