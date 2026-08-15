<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\JournalAttendance;
use App\Models\JournalGrade;
use App\Models\JournalLesson;
use App\Models\NotificationSubscription;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Notifications\DailyJournalDigestNotifier;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\Notifications\MaxNotificationChannel;
use App\Support\Notifications\NotificationChannel;
use App\Support\Notifications\NotificationChannels;
use App\Support\Notifications\NotificationEvents;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сводки за день. Владелец выбрал сводку вместо отправки по факту, и тесты закрепляют
 * то, что из этого следует: одно сообщение на день, молчание в пустой день и отсутствие
 * сообщений о присутствии — о нём человеку объясняться не придётся.
 */
class JournalDigestNotificationTest extends TestCase
{
    use RefreshDatabase;

    private DigestChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->channel = new DigestChannel;
        $this->app->instance(NotificationChannels::class, new NotificationChannels([$this->channel]));
    }

    public function test_the_grades_of_the_day_arrive_as_one_message(): void
    {
        [$user, $student, $lesson] = $this->subscribedStudent(NotificationEvents::GRADES_DAILY);
        $this->grade($lesson, $student, '5');
        $this->grade($lesson, $student, '4');

        $result = $this->notifier()->run(now(), MaxNotificationChannel::CODE);

        $this->assertSame(1, $result['grades']);
        $this->assertCount(1, $this->channel->sent);
        $this->assertStringContainsString('5', $this->channel->sent[0][1]);
        $this->assertStringContainsString('4', $this->channel->sent[0][1]);
    }

    public function test_a_day_without_grades_is_silent(): void
    {
        $this->subscribedStudent(NotificationEvents::GRADES_DAILY);

        $result = $this->notifier()->run(now(), MaxNotificationChannel::CODE);

        $this->assertSame(0, $result['grades']);
        $this->assertSame([], $this->channel->sent);
    }

    /** Присутствие — не новость: сообщаем только о том, что придётся объяснять. */
    public function test_being_present_does_not_produce_a_message(): void
    {
        [$user, $student, $lesson] = $this->subscribedStudent(NotificationEvents::ATTENDANCE_DAILY);
        $this->attendance($lesson, $student, JournalAttendance::STATUS_PRESENT);

        $result = $this->notifier()->run(now(), MaxNotificationChannel::CODE);

        $this->assertSame(0, $result['attendance']);
        $this->assertSame([], $this->channel->sent);
    }

    public function test_an_absence_and_a_late_arrival_are_reported_with_minutes(): void
    {
        [$user, $student, $lesson] = $this->subscribedStudent(NotificationEvents::ATTENDANCE_DAILY);
        $this->attendance($lesson, $student, JournalAttendance::STATUS_LATE, 12);

        $result = $this->notifier()->run(now(), MaxNotificationChannel::CODE);

        $this->assertSame(1, $result['attendance']);
        $this->assertStringContainsString('опоздание на 12 мин', $this->channel->sent[0][1]);
    }

    public function test_running_twice_does_not_send_the_digest_twice(): void
    {
        [$user, $student, $lesson] = $this->subscribedStudent(NotificationEvents::GRADES_DAILY);
        $this->grade($lesson, $student, '5');

        $this->notifier()->run(now(), MaxNotificationChannel::CODE);
        $this->notifier()->run(now(), MaxNotificationChannel::CODE);

        $this->assertCount(1, $this->channel->sent);
    }

    private function notifier(): DailyJournalDigestNotifier
    {
        return new DailyJournalDigestNotifier(new NotificationDispatcher(app(NotificationChannels::class)));
    }

    /** @return array{0: User, 1: Student, 2: JournalLesson} */
    private function subscribedStudent(string $event): array
    {
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Инструментальное исполнительство', 'course' => 1, 'year_start' => 2026]);
        $subject = Subject::create(['name' => 'Сольфеджио', 'code' => 'SOLF1']);
        $user = $this->createApiUser();

        $student = Student::create([
            'user_id' => $user->id,
            'group_id' => $group->id,
            'last_name' => 'Горбачева',
            'first_name' => 'Татьяна',
            'status' => 'active',
        ]);

        // Преподаватель у занятия обязателен на уровне схемы: без него вставка падает
        // на NOT NULL, а не на проверке приложения.
        $teacher = Teacher::create(['last_name' => 'Власова', 'first_name' => 'Ирина', 'status' => 'active']);

        $lesson = JournalLesson::create([
            'group_id' => $group->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'lesson_date' => now()->toDateString(),
            'starts_at' => '09:00',
            'ends_at' => '10:30',
            'status' => 'open',
        ]);

        NotificationSubscription::create([
            'user_id' => $user->id,
            'event' => $event,
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

        return [$user, $student, $lesson];
    }

    private function grade(JournalLesson $lesson, Student $student, string $value): void
    {
        JournalGrade::create([
            'journal_lesson_id' => $lesson->id,
            'student_id' => $student->id,
            'value' => $value,
            'marked_at' => now(),
        ]);
    }

    private function attendance(JournalLesson $lesson, Student $student, string $status, ?int $minutesLate = null): void
    {
        JournalAttendance::create([
            'journal_lesson_id' => $lesson->id,
            'student_id' => $student->id,
            'status' => $status,
            'minutes_late' => $minutesLate,
            'marked_at' => now(),
        ]);
    }
}

final class DigestChannel implements NotificationChannel
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
