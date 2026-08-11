<?php

namespace Tests\Feature;

use App\Models\NotificationDelivery;
use App\Models\NotificationSubscription;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\Notifications\NotificationChannel;
use App\Support\Notifications\NotificationChannels;
use App\Support\Notifications\NotificationEvents;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `NOTIFY-001`: согласие, доставка и защита от повтора.
 *
 * Канал здесь поддельный — и это не упрощение, а то же решение, что с провайдером
 * Telegram: настоящая отправка проверяется живьём со стенда, а тестами закрепляются
 * правила, из-за которых портал **не** пишет человеку. Их три, и каждое легко потерять
 * при добавлении нового события, поэтому все три собраны в одном месте — диспетчере.
 */
class NotificationSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private FakeNotificationChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->channel = new FakeNotificationChannel;
        $this->app->instance(NotificationChannels::class, new NotificationChannels([$this->channel]));
    }

    public function test_by_default_nobody_is_subscribed_to_anything(): void
    {
        $response = $this->withApiAuth($this->createApiUser())->getJson('/api/account/notifications');

        $response->assertOk();
        $response->assertJsonPath('data.subscribed', []);
        $this->assertNotEmpty($response->json('data.events'));
    }

    public function test_a_checkbox_goes_on_and_off(): void
    {
        $user = $this->createApiUser();

        $this->withApiAuth($user)->postJson('/api/account/notifications', [
            'event' => NotificationEvents::LESSONS_TOMORROW,
            'channel' => 'проверочный',
            'enabled' => true,
        ])->assertOk();

        $this->assertDatabaseHas('notification_subscriptions', [
            'user_id' => $user->id,
            'event' => NotificationEvents::LESSONS_TOMORROW,
        ]);

        $this->withApiAuth($user)->postJson('/api/account/notifications', [
            'event' => NotificationEvents::LESSONS_TOMORROW,
            'channel' => 'проверочный',
            'enabled' => false,
        ])->assertOk();

        $this->assertDatabaseCount('notification_subscriptions', 0);
    }

    public function test_an_unknown_event_or_channel_is_refused(): void
    {
        $user = $this->createApiUser();

        $this->withApiAuth($user)->postJson('/api/account/notifications', [
            'event' => 'выдуманное.событие',
            'channel' => 'проверочный',
            'enabled' => true,
        ])->assertStatus(422);

        $this->withApiAuth($user)->postJson('/api/account/notifications', [
            'event' => NotificationEvents::LESSONS_TOMORROW,
            'channel' => 'почтовый-голубь',
            'enabled' => true,
        ])->assertStatus(422);
    }

    /** Нет галочки — нет сообщения. Это первое из трёх условий диспетчера. */
    public function test_without_a_checkbox_nothing_is_sent(): void
    {
        $user = $this->userWithChat();

        $this->dispatcher()->send($user, NotificationEvents::LESSONS_TOMORROW, 'ключ-1', 'текст', 'проверочный');

        $this->assertSame([], $this->channel->sent);
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    /**
     * Галочка стоит, а «Старт» не нажат: писать некуда. Это записывается в журнал —
     * иначе на вопрос «почему не приходит» ответить будет нечем.
     */
    public function test_a_subscription_without_a_started_dialog_is_recorded_and_not_sent(): void
    {
        $user = $this->createApiUser();
        $this->subscribe($user);

        $this->dispatcher()->send($user, NotificationEvents::LESSONS_TOMORROW, 'ключ-1', 'текст', 'проверочный');

        $this->assertSame([], $this->channel->sent);
        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $user->id,
            'status' => NotificationDelivery::STATUS_SKIPPED,
            'failure_reason' => 'Диалог с ботом не начат',
        ]);
    }

    public function test_a_subscribed_user_with_a_dialog_gets_the_message(): void
    {
        $user = $this->userWithChat();
        $this->subscribe($user);

        $this->dispatcher()->send($user, NotificationEvents::LESSONS_TOMORROW, 'ключ-1', 'Завтра три занятия', 'проверочный');

        $this->assertSame([['555', 'Завтра три занятия']], $this->channel->sent);
        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $user->id,
            'status' => NotificationDelivery::STATUS_SENT,
            'attempts' => 1,
        ]);
    }

    /**
     * Планировщик может сработать дважды, задание из очереди — повториться. Человек
     * не должен получить «Занятия на завтра» дважды за один вечер.
     */
    public function test_the_same_notification_is_not_sent_twice(): void
    {
        $user = $this->userWithChat();
        $this->subscribe($user);

        $this->dispatcher()->send($user, NotificationEvents::LESSONS_TOMORROW, 'ключ-1', 'текст', 'проверочный');
        $this->dispatcher()->send($user, NotificationEvents::LESSONS_TOMORROW, 'ключ-1', 'текст', 'проверочный');

        $this->assertCount(1, $this->channel->sent);
        $this->assertDatabaseCount('notification_deliveries', 1);
    }

    /** Отказ канала — не потеря: он записан, и по журналу видно, что повторить. */
    public function test_a_refusing_channel_leaves_a_trace(): void
    {
        $user = $this->userWithChat();
        $this->subscribe($user);
        $this->channel->accepts = false;

        $this->dispatcher()->send($user, NotificationEvents::LESSONS_TOMORROW, 'ключ-1', 'текст', 'проверочный');

        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $user->id,
            'status' => NotificationDelivery::STATUS_FAILED,
            'attempts' => 1,
        ]);
    }

    public function test_without_a_configured_channel_there_is_nothing_to_switch_on(): void
    {
        $this->app->instance(NotificationChannels::class, new NotificationChannels([]));
        $user = $this->createApiUser();

        $this->withApiAuth($user)->getJson('/api/account/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data.channels');

        $this->withApiAuth($user)->postJson('/api/account/notifications', [
            'event' => NotificationEvents::LESSONS_TOMORROW,
            'channel' => 'проверочный',
            'enabled' => true,
        ])->assertStatus(422);
    }

    private function dispatcher(): NotificationDispatcher
    {
        return new NotificationDispatcher(app(NotificationChannels::class));
    }

    private function subscribe(User $user): void
    {
        NotificationSubscription::create([
            'user_id' => $user->id,
            'event' => NotificationEvents::LESSONS_TOMORROW,
            'channel' => 'проверочный',
        ]);
    }

    private function userWithChat(): User
    {
        $user = $this->createApiUser();

        UserIdentity::create([
            'user_id' => $user->id,
            'provider' => 'проверочный',
            'provider_user_id' => '777',
            'chat_id' => '555',
            'chat_started_at' => now(),
            'linked_at' => now(),
        ]);

        return $user;
    }
}

final class FakeNotificationChannel implements NotificationChannel
{
    /** @var list<array{0: string, 1: string}> */
    public array $sent = [];

    public bool $accepts = true;

    public function code(): string
    {
        return 'проверочный';
    }

    public function name(): string
    {
        return 'Проверочный канал';
    }

    public function send(string $chatId, string $text): bool
    {
        if (! $this->accepts) {
            return false;
        }

        $this->sent[] = [$chatId, $text];

        return true;
    }
}
