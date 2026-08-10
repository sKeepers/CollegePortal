<?php

namespace Tests\Unit\Rules;

use App\Rules\Inn;
use Tests\TestCase;

/**
 * У ИНН две длины и разное число контрольных разрядов: один у десятизначного и два у
 * двенадцатизначного. Проверять только последний разряд мало — опечатка в предпоследнем
 * тогда проходит, а именно такие опечатки и делают при переписывании номера с документа.
 */
class InnTest extends TestCase
{
    public function test_it_accepts_correct_numbers_of_both_lengths(): void
    {
        $this->assertTrue(Inn::checksumValid('7707083893'));
        $this->assertTrue(Inn::checksumValid('500100123426'));
    }

    public function test_it_rejects_a_broken_last_digit(): void
    {
        $this->assertFalse(Inn::checksumValid('7707083894'));
        $this->assertFalse(Inn::checksumValid('500100123427'));
    }

    /** Первый из двух контрольных разрядов проверяется наравне со вторым. */
    public function test_it_rejects_a_broken_eleventh_digit(): void
    {
        $this->assertFalse(Inn::checksumValid('500100123496'));
    }

    public function test_it_rejects_lengths_that_do_not_exist(): void
    {
        $this->assertFalse(Inn::checksumValid('12345678901'));
        $this->assertFalse(Inn::checksumValid('770708389'));
    }
}
