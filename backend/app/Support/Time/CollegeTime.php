<?php

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Сутки колледжа, выраженные в том времени, в котором лежит база.
 *
 * Приложение живёт в UTC (`config/app.php`), и это правильно: в базе однозначное
 * время, не зависящее от того, где стоит сервер. Но человек выбирает **день по
 * календарю колледжа**, а не отрезок UTC, и между этими двумя вещами три часа
 * разницы. Пока их не разводили, отбор «за 22 августа» отрезал первые три часа
 * суток и прихватывал три часа предыдущих.
 *
 * Замерено на стенде 24.08.2026, карта выдана в 00:17 по колледжу:
 *
 * ```
 * отбор «за 2026-08-22»: строк 0     ← а карта выдана именно 22-го
 * отбор «за 2026-08-21»: строк 2
 * ```
 *
 * То есть ведомость за день выдачи печаталась **пустой** — при верных данных в
 * базе. Это тот самый симптом, на котором владелец уже обжёгся однажды по
 * совсем другой причине, и второй раз он прочитал бы его как «печать опять
 * сломалась».
 *
 * **Пояс приложения при этом не трогается.** Поменять `app.timezone` — правка
 * в одну строку, после которой Laravel начнёт записывать местное время рядом с
 * двумя годами UTC, и различить их потом будет нечем: расхождение на части
 * строк хуже ровного расхождения на всех. Разбор целиком —
 * `docs/TIME_ON_PRINTED_DOCUMENTS.md`.
 *
 * **Границы нужны только там, где день человека сравнивается со временем.**
 * Колонка типа `date` — `journal_lessons.lesson_date`, `schedule_lessons.lesson_date`,
 * `dorm_payments.paid_through` — часового пояса не имеет, и переводить её не
 * надо: проверено запросом к схеме стенда. Беда живёт там, где колонка
 * `timestamp`: `access_events.event_time`, `rfid_card_issues.issued_at`,
 * `dorm_incidents.happened_at`, `audit_logs.created_at`.
 */
final class CollegeTime
{
    /**
     * Пояс колледжа.
     *
     * Ставропольский край живёт по московскому времени. Константой, а не
     * настройкой, намеренно: подтверждения от владельца на 28.08.2026 ещё нет,
     * а жёстко заданный `Europe/Moscow` **верен**, тогда как нынешний UTC
     * заведомо неверен — ждать ответа, чтобы перестать терять строки, незачем.
     * Когда ответ придёт, значение переедет в настройку, а всё остальное здесь
     * не изменится.
     */
    public const ZONE = 'Europe/Moscow';

    public static function zone(): string
    {
        return self::ZONE;
    }

    /** Начало календарного дня колледжа, выраженное в UTC. */
    public static function dayStart(CarbonInterface|string $date): CarbonImmutable
    {
        return CarbonImmutable::parse(self::dateString($date), self::ZONE)
            ->startOfDay()
            ->utc();
    }

    /** Конец того же дня, тоже в UTC. */
    public static function dayEnd(CarbonInterface|string $date): CarbonImmutable
    {
        return CarbonImmutable::parse(self::dateString($date), self::ZONE)
            ->endOfDay()
            ->utc();
    }

    /**
     * Отрезок «за этот день» — пара границ для `whereBetween`.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function dayRange(CarbonInterface|string $date): array
    {
        return [self::dayStart($date), self::dayEnd($date)];
    }

    /**
     * Момент внутри дня колледжа, выраженный в UTC.
     *
     * Нужен там, где час назван по местным часам: «до восьми утра не вернулся»
     * — это восемь по колледжу, а не восемь по часам сервера. Разница в три
     * часа переносит границу ночи так, что вернувшийся в начале первого
     * попадает в отсутствующие, а вернувшийся в одиннадцатом — нет.
     */
    public static function at(CarbonInterface|string $date, int $hour, int $minute = 0): CarbonImmutable
    {
        return CarbonImmutable::parse(self::dateString($date), self::ZONE)
            ->setTime($hour, $minute)
            ->utc();
    }

    /** Сегодняшнее число по календарю колледжа, а не по календарю сервера. */
    public static function todayDate(): string
    {
        return CarbonImmutable::now(self::ZONE)->toDateString();
    }

    /**
     * Какой день колледжа считать своим для этой отметки времени.
     *
     * Отметка в 21:30 UTC относится к **следующему** дню колледжа: там уже
     * половина первого ночи. Отсюда и берётся правило перевода, а не из
     * календаря сервера.
     */
    private static function dateString(CarbonInterface|string $date): string
    {
        if ($date instanceof CarbonInterface) {
            return $date->copy()->setTimezone(self::ZONE)->toDateString();
        }

        return $date;
    }
}
