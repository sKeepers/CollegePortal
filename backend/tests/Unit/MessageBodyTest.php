<?php

namespace Tests\Unit;

use App\Support\Notifications\MessageBody;
use PHPUnit\Framework\TestCase;

/**
 * Длина сообщения-списка.
 *
 * Свёртка по числу сообщений в рассылках была всегда, а длина самого сообщения не
 * ограничивалась ничем. Замер на стенде: шестьдесят изменённых занятий — 2662 знака,
 * то есть предел мессенджера переступается примерно на девяноста. Слишком длинное
 * сообщение не приходит обрезанным — оно не приходит вовсе.
 */
class MessageBodyTest extends TestCase
{
    public function test_short_list_is_left_whole(): void
    {
        $text = MessageBody::list('Заголовок:', ['первая', 'вторая']);

        $this->assertSame("Заголовок:\nпервая\nвторая", $text);
    }

    public function test_long_list_is_cut_and_says_how_many_are_left(): void
    {
        $lines = array_map(fn (int $i): string => 'занятие '.$i, range(1, 300));

        $text = MessageBody::list('Расписание изменилось, занятий: 300', $lines);

        $this->assertStringContainsString('занятие 10', $text);
        $this->assertStringNotContainsString('занятие 11', $text);
        $this->assertStringEndsWith('…и ещё 290', $text);
        $this->assertLessThan(4000, mb_strlen($text), 'Сообщение не должно упираться в предел мессенджера.');
    }

    public function test_empty_list_leaves_only_the_title(): void
    {
        $this->assertSame('Ничего нет', MessageBody::list('Ничего нет', []));
    }

    public function test_blank_lines_do_not_eat_the_limit(): void
    {
        $text = MessageBody::list('Заголовок:', ['первая', '   ', '', 'вторая']);

        $this->assertSame("Заголовок:\nпервая\nвторая", $text);
    }
}
