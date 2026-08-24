<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\JournalLesson;
use App\Models\NotificationSubscription;
use App\Models\ScheduleEntry;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\ScheduleEngineService;
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

    /**
     * Счётчик дисциплин.
     *
     * Код брался случайным числом из девятисот, и пока помощник звали по разу за тест,
     * это сходило с рук. Тест про отмену зовёт его двенадцать раз подряд — совпадение
     * выпадает примерно раз в четырнадцать полных прогонов, и выглядит это не как
     * совпадение, а как «отмена вдруг перестала работать». Счётчик убирает случайность
     * совсем.
     */
    private int $subjectNumber = 0;

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

    /**
     * Отмена — самая дорогая новость, и до 24.08.2026 она не доходила вовсе.
     *
     * `ScheduleEngineService::cancel()` **удаляет** зеркальную строку `schedule_lessons`,
     * мягкого удаления у модели нет, а рассылка изменений смотрела только туда — найти
     * удалённую строку она не могла в принципе. Выходило наоборот тому, что нужно: о
     * переносе аудитории человек узнавал, а о том, что занятия не будет, — нет, и
     * приходил в колледж зря.
     */
    public function test_a_cancelled_lesson_reaches_the_group(): void
    {
        [, $group, $teacher] = $this->subscribedStudent(NotificationEvents::SCHEDULE_CHANGED);
        $entry = $this->entry($group, $teacher, now()->addDay());

        app(ScheduleEngineService::class)->cancel($entry);

        $result = $this->scheduleNotifier()->run(now()->subMinutes(15), MaxNotificationChannel::CODE);

        $this->assertSame(1, $result['sent']);
        $this->assertStringContainsString('отменено', $this->channel->sent[0][1]);
        $this->assertStringContainsString(now()->addDay()->format('d.m'), $this->channel->sent[0][1]);
    }

    /**
     * Отменили весь день — это тридцать занятий разом, и тридцать сообщений было бы той
     * же лавиной с другой стороны. Свёртка обязана держать и здесь.
     */
    public function test_a_whole_day_of_cancellations_is_one_message(): void
    {
        [, $group, $teacher] = $this->subscribedStudent(NotificationEvents::SCHEDULE_CHANGED);
        $engine = app(ScheduleEngineService::class);

        for ($i = 0; $i < 30; $i++) {
            $engine->cancel($this->entry($group, $teacher, now()->addDay(), $i + 1));
        }

        $result = $this->scheduleNotifier()->run(now()->subMinutes(15), MaxNotificationChannel::CODE);

        $this->assertSame(30, $result['changed']);
        $this->assertSame(1, $result['sent']);
        $this->assertCount(1, $this->channel->sent);
        $this->assertStringContainsString('занятий: 30', $this->channel->sent[0][1]);
        // Десять строк и «и ещё 20» — длинное сообщение мессенджер не обрезает, он его
        // не доставляет вовсе.
        $this->assertStringContainsString('и ещё 20', $this->channel->sent[0][1]);
    }

    /**
     * Отмена стоит первой строкой, даже если правок больше.
     *
     * Список подрезается десятью строками: вытесни отмену переносами — и человек
     * прочитает про аудиторию, а придёт на занятие, которого нет.
     */
    public function test_a_cancellation_is_not_pushed_out_by_ordinary_changes(): void
    {
        [, $group, $teacher] = $this->subscribedStudent(NotificationEvents::SCHEDULE_CHANGED);

        for ($i = 0; $i < 12; $i++) {
            $this->markEdited($this->lesson($group, $teacher, now()->addDays(2)));
        }

        app(ScheduleEngineService::class)->cancel($this->entry($group, $teacher, now()->addDay()));

        $this->scheduleNotifier()->run(now()->subMinutes(15), MaxNotificationChannel::CODE);

        $lines = explode("\n", $this->channel->sent[0][1]);
        $this->assertStringContainsString('отменено', $lines[1], 'Отмена обязана стоять первой строкой списка.');
    }

    /**
     * Занятие, заведённое и отменённое в одну минуту, новостью не является: о нём никто
     * не успел узнать. Тот же порог, что и у правок.
     */
    public function test_a_lesson_cancelled_at_once_is_not_news(): void
    {
        [, $group, $teacher] = $this->subscribedStudent(NotificationEvents::SCHEDULE_CHANGED);
        $entry = $this->entry($group, $teacher, now()->addDay());
        $entry->forceFill(['created_at' => now()])->saveQuietly();

        app(ScheduleEngineService::class)->cancel($entry);

        $this->assertSame(0, $this->scheduleNotifier()->run(now()->subMinutes(15), MaxNotificationChannel::CODE)['sent']);
    }

    /**
     * Отменённое занятие могут вернуть — и человек, которому сказали «отменено», обязан
     * узнать об этом. Иначе починка отмены выходит половинчатой: он не придёт на занятие,
     * которое состоится.
     *
     * Возврат заводит зеркальную строку заново, поэтому правкой он не выглядит и отменой
     * уже не является: ищется третьим способом — новая строка при старой записи движка.
     */
    public function test_a_restored_lesson_is_announced_too(): void
    {
        [, $group, $teacher] = $this->subscribedStudent(NotificationEvents::SCHEDULE_CHANGED);
        $engine = app(ScheduleEngineService::class);
        $entry = $this->entry($group, $teacher, now()->addDay());

        $engine->cancel($entry);
        $engine->restore($entry->fresh());

        $result = $this->scheduleNotifier()->run(now()->subMinutes(15), MaxNotificationChannel::CODE);

        $this->assertSame(1, $result['sent']);
        $this->assertStringContainsString('снова в расписании', $this->channel->sent[0][1]);
        $this->assertStringNotContainsString('отменено', $this->channel->sent[0][1]);
    }

    /**
     * Загрузка расписания на семестр возвратом не считается.
     *
     * У созданного занятия строка расписания и запись движка появляются в одной
     * транзакции, разница между ними — микросекунды. Порог в минуту разводит это с
     * настоящим возвратом, где занятие простояло отменённым заметное время. Без порога
     * первая же загрузка семестра разослала бы «снова в расписании» всему колледжу.
     */
    public function test_a_freshly_created_lesson_is_not_a_restoration(): void
    {
        [, $group, $teacher] = $this->subscribedStudent(NotificationEvents::SCHEDULE_CHANGED);
        $subject = Subject::create(['name' => 'Гармония', 'code' => 'HARM1']);

        $entry = ScheduleEntry::create([
            'academic_year' => '2026/2027', 'semester' => 1,
            'date' => now()->addDay()->toDateString(), 'day_of_week' => now()->addDay()->dayOfWeekIso,
            'lesson_number' => 1, 'starts_at' => '09:00', 'ends_at' => '10:30',
            'group_id' => $group->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            'status' => 'scheduled', 'source' => 'schedule_template',
        ]);
        ScheduleLesson::create([
            'schedule_entry_id' => $entry->id, 'group_id' => $group->id, 'teacher_id' => $teacher->id,
            'subject_id' => $subject->id, 'lesson_date' => now()->addDay()->toDateString(),
            'starts_at' => '09:00', 'ends_at' => '10:30', 'lesson_type' => 'lecture',
        ]);

        $this->assertSame(0, $this->scheduleNotifier()->run(now()->subMinutes(15), MaxNotificationChannel::CODE)['sent']);
    }

    /** Запись движка с зеркальной строкой, заведённая заметно раньше — как настоящая. */
    private function entry(Group $group, Teacher $teacher, $date, int $lessonNumber = 1): ScheduleEntry
    {
        $subject = Subject::create(['name' => 'Сольфеджио', 'code' => 'SOLF'.(++$this->subjectNumber)]);

        // Пары идут с восьми утра с шагом в четверть часа: тридцать штук укладываются
        // в сутки, а без этого тридцатая получала бы «37:00» и роняла разбор времени.
        $startMinute = 8 * 60 + ($lessonNumber - 1) * 15;
        $start = sprintf('%02d:%02d', intdiv($startMinute, 60), $startMinute % 60);
        $end = sprintf('%02d:%02d', intdiv($startMinute + 10, 60), ($startMinute + 10) % 60);

        $entry = ScheduleEntry::create([
            'academic_year' => '2026/2027',
            'semester' => 1,
            'date' => $date->toDateString(),
            'day_of_week' => $date->dayOfWeekIso,
            'lesson_number' => $lessonNumber,
            'starts_at' => $start,
            'ends_at' => $end,
            'group_id' => $group->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'status' => 'scheduled',
            'source' => 'schedule_engine',
        ]);

        $entry->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();

        ScheduleLesson::create([
            'schedule_entry_id' => $entry->id,
            'group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'lesson_date' => $date->toDateString(),
            'starts_at' => $start,
            'ends_at' => $end,
            'lesson_type' => 'lecture',
        ]);

        return $entry->fresh();
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

    private function journalLesson(Group $group, Teacher $teacher, string $status): JournalLesson
    {
        $subject = Subject::create(['name' => 'Гармония', 'code' => 'HARM'.(++$this->subjectNumber)]);

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
