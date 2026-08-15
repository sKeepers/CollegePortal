<?php

namespace App\Services\Notifications;

use App\Models\NotificationChannelCursor;
use App\Models\NotificationLinkCode;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\AuditLogService;
use App\Support\Notifications\MaxNotificationChannel;
use Illuminate\Support\Str;

/**
 * Привязка MAX к учётной записи и вычитывание очереди обновлений бота.
 *
 * Порядок такой: вошедший человек берёт в кабинете одноразовый код, открывает бота,
 * нажимает «Старт» и отправляет код. Портал забирает очередь обновлений, находит
 * сообщение с живым кодом и связывает учётную запись с диалогом.
 *
 * **Почему не проще.** Бот видит идентификатор человека в MAX, но не знает, кто это в
 * портале, и узнать не может: у мессенджера нет ничего общего с учётной записью, кроме
 * того, что человек сам предъявит. Код и есть это предъявление.
 *
 * Правило слоя `AUTH-005` соблюдается буквально: **привязка не создаёт учётную запись
 * никогда.** Код выдаётся только вошедшему, поэтому привязать себя к чужой учётной
 * записи нельзя — некому.
 */
class MaxLinkService
{
    public const CODE_TTL_MINUTES = 15;

    public function __construct(private readonly MaxNotificationChannel $channel)
    {
    }

    /**
     * Одноразовый код для привязки. Прежние коды этого человека гасятся: два живых
     * кода на одного означали бы, что забытый вчерашний всё ещё работает.
     */
    public function issueCode(User $user): NotificationLinkCode
    {
        NotificationLinkCode::query()
            ->where('user_id', $user->id)
            ->where('channel', MaxNotificationChannel::CODE)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        // Без похожих начертаний: код человек переписывает с экрана в мессенджер.
        $code = Str::upper(Str::password(6, letters: true, numbers: true, symbols: false, spaces: false));
        $code = str_replace(['O', '0', 'I', 'L', '1'], ['A', '2', 'B', 'C', '3'], $code);

        return NotificationLinkCode::create([
            'user_id' => $user->id,
            'channel' => MaxNotificationChannel::CODE,
            'code' => $code,
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ]);
    }

    /**
     * Забрать очередь обновлений и связать тех, кто прислал живой код.
     *
     * Очередь у бота **одна** и читается указателем, поэтому вызывать это должен ровно
     * один процесс — запланированная задача. Два читателя растащили бы события.
     *
     * @return int сколько привязок сделано
     */
    public function pullUpdates(): int
    {
        // `firstOrNew`, а не `firstOrCreate`: последний внутри транзакции открывает
        // точку сохранения, а их количество ограничено на весь сервер — см. «Грабли».
        $cursor = NotificationChannelCursor::firstOrNew(['channel' => MaxNotificationChannel::CODE]);

        if (! $cursor->exists) {
            $cursor->save();
        }
        $result = $this->channel->fetchUpdates($cursor->marker);
        $linked = 0;

        foreach ($result['updates'] as $update) {
            if ($this->handle($update)) {
                $linked++;
            }
        }

        if ($result['marker'] !== null) {
            $cursor->forceFill(['marker' => $result['marker']])->save();
        }

        return $linked;
    }

    /**
     * @param array<string, mixed> $update
     */
    private function handle(array $update): bool
    {
        $text = trim((string) data_get($update, 'message.body.text', ''));
        $chatId = data_get($update, 'message.recipient.chat_id') ?? data_get($update, 'chat_id');
        $userId = data_get($update, 'message.sender.user_id') ?? data_get($update, 'user_id');

        if ($text === '' || blank($chatId) || blank($userId)) {
            // `bot_started` без текста — ещё не привязка: код человек присылает следующим
            // сообщением. Само по себе нажатие «Старт» не говорит, кто это в портале.
            return false;
        }

        $code = NotificationLinkCode::query()
            ->where('channel', MaxNotificationChannel::CODE)
            ->where('code', Str::upper($text))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($code === null) {
            return false;
        }

        $this->link($code, (string) $userId, (string) $chatId);

        return true;
    }

    private function link(NotificationLinkCode $code, string $providerUserId, string $chatId): void
    {
        $identity = UserIdentity::query()
            ->where('user_id', $code->user_id)
            ->where('provider', MaxNotificationChannel::CODE)
            ->first();

        if ($identity === null) {
            $identity = new UserIdentity([
                'user_id' => $code->user_id,
                'provider' => MaxNotificationChannel::CODE,
                'provider_user_id' => $providerUserId,
                'linked_at' => now(),
                'linked_by' => $code->user_id,
            ]);
        }

        $identity->forceFill([
            'provider_user_id' => $providerUserId,
            'chat_id' => $chatId,
            'chat_started_at' => now(),
        ])->save();

        $code->forceFill(['used_at' => now()])->save();

        AuditLogService::log('auth', 'notification_channel_linked', $identity, null, [
            'channel' => MaxNotificationChannel::CODE,
        ], null, $code->user);
    }
}
