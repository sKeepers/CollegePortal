<?php

namespace Tests\Unit\Admissions;

use App\Models\Person;
use App\Services\Admissions\SnilsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SnilsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_normalizes_and_validates_snils_checksum(): void
    {
        $service = app(SnilsService::class);

        $this->assertSame('112-233-445 95', $service->normalize('11223344595'));
        $this->assertTrue($service->checksumValid('11223344595'));
    }

    public function test_it_rejects_invalid_snils_checksum(): void
    {
        $this->expectException(ValidationException::class);

        app(SnilsService::class)->normalize('112-233-445 96');
    }

    public function test_it_rejects_duplicate_snils_hash(): void
    {
        $service = app(SnilsService::class);
        $first = Person::query()->create(['last_name' => 'Первый', 'first_name' => 'Тест', 'status' => 'active']);
        $second = Person::query()->create(['last_name' => 'Второй', 'first_name' => 'Тест', 'status' => 'active']);

        $service->update($first, '112-233-445 95');

        $this->expectException(ValidationException::class);
        $service->update($second, '11223344595');
    }
}
