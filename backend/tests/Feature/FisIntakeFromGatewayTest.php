<?php

namespace Tests\Feature;

use App\Services\FisIntegration\GatewayFisTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Загрузка данных ФИС **без файла** — портал спрашивает их у шлюза сам.
 *
 * Раньше приём умел только файл. После того как шлюз научился приносить XML,
 * оператору пришлось бы нажать диагностику, скопировать ответ, сохранить его в
 * файл и загрузить обратно в тот же портал.
 */
class FisIntakeFromGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_it_previews_the_dictionary_list_taken_from_fis(): void
    {
        $this->transportReturns('dictionariesList', [
            'ok' => true,
            'data' => '<?xml version="1.0" encoding="utf-8"?><Dictionaries><Dictionary><Code>14</Code><Name>Форма обучения</Name></Dictionary></Dictionaries>',
        ]);

        $this->postJson('/api/fis/dictionaries/preview', ['fetch' => 'dictionaries'])
            ->assertOk()
            ->assertJsonPath('data.kind', 'dictionary_list')
            ->assertJsonPath('data.dictionaries.0.code', '14');
    }

    /** Состав справочника без кода запрашивать бессмысленно — выбирать нечего. */
    public function test_a_dictionary_needs_its_code(): void
    {
        $this->postJson('/api/fis/dictionaries/preview', ['fetch' => 'dictionary'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    /** Код доходит до шлюза: раньше выбор оператора терялся по дороге. */
    public function test_the_chosen_code_reaches_the_gateway(): void
    {
        $transport = Mockery::mock(GatewayFisTransport::class);
        $transport->shouldReceive('dictionaryDetails')->once()->with('99')->andReturn([
            'ok' => true,
            'data' => '<?xml version="1.0" encoding="utf-8"?><DictionaryData><Code>99</Code><Name>Прочий справочник</Name><DictionaryItems><DictionaryItem><ID>1</ID><Name>Очная</Name></DictionaryItem></DictionaryItems></DictionaryData>',
        ]);
        $this->app->instance(GatewayFisTransport::class, $transport);

        $this->postJson('/api/fis/dictionaries/preview', ['fetch' => 'dictionary', 'code' => 99])
            ->assertOk()
            ->assertJsonPath('data.dictionary.code', '99');
    }

    /**
     * Отказ ФИС приходит с успешным кодом HTTP и телом `Error`. Портал обязан
     * пересказать причину, а не показать пустой разбор.
     */
    public function test_a_refusal_from_fis_is_passed_through(): void
    {
        $this->transportReturns('dictionariesList', [
            'ok' => false,
            'error_code' => 'fis_error',
            'message' => 'Ошибка валидации XML. Не найден тег AuthData',
        ]);

        $this->postJson('/api/fis/dictionaries/preview', ['fetch' => 'dictionaries'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'fis_error: Ошибка валидации XML. Не найден тег AuthData');
    }

    /** Пустой ответ — тоже отказ, а не «загрузили ноль записей». */
    public function test_an_empty_answer_is_refused(): void
    {
        $this->transportReturns('dictionariesList', ['ok' => true, 'data' => '']);

        $this->postJson('/api/fis/dictionaries/preview', ['fetch' => 'dictionaries'])
            ->assertStatus(409);
    }

    /** Без файла и без запроса делать нечего. */
    public function test_either_a_file_or_a_fetch_is_required(): void
    {
        $this->postJson('/api/fis/dictionaries/preview', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file', 'fetch']);
    }

    private function transportReturns(string $method, array $answer): void
    {
        $transport = Mockery::mock(GatewayFisTransport::class);
        $transport->shouldReceive($method)->andReturn($answer);
        $this->app->instance(GatewayFisTransport::class, $transport);
    }
}
