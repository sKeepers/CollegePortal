<?php

namespace Tests\Feature;

use App\Models\FisExternalMapping;
use App\Models\Permission;
use App\Models\ReferenceCatalog;
use App\Models\ReferenceItem;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Загрузка справочников ФИС ГИА и Приёма.
 *
 * Ответы описаны в спецификации 4.9 (методы 2.8 и 2.9). Отдельного XSD для них
 * нет, поэтому разбор идёт по именам элементов, а тесты закрепляют ровно ту
 * форму, которая нарисована в спецификации.
 */
class FisDictionaryIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_directions_dictionary_becomes_specialties_and_mappings(): void
    {
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWith(['fis.outbound.view', 'fis.settings.manage']));
        Specialty::query()->create(['code' => '53.02.03', 'name' => 'Старое название']);

        $response = $this->post('/api/fis/dictionaries/apply', ['file' => $this->file($this->directionsXml())])
            ->assertOk()
            ->assertJsonPath('data.kind', 'directions')
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.mapped', 2);

        $this->assertSame('Инструментальное исполнительство', Specialty::query()->where('code', '53.02.03')->value('name'));
        $this->assertSame('Вокальное искусство', Specialty::query()->where('code', '53.02.04')->value('name'));

        $mapping = FisExternalMapping::query()
            ->where('entity_type', Specialty::class)
            ->where('external_type', 'fis:DirectionID')
            ->where('external_id', '1235')
            ->firstOrFail();
        $this->assertSame('53.00.00', $mapping->metadata['ugs_code']);
        $this->assertSame([], $response->json('data.skipped'));
    }

    public function test_plain_dictionary_maps_reference_items_and_names_what_did_not_match(): void
    {
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWith(['fis.outbound.view', 'fis.settings.manage']));

        $this->post('/api/fis/dictionaries/apply', ['file' => $this->file($this->statusesXml())])
            ->assertOk()
            ->assertJsonPath('data.kind', 'plain')
            ->assertJsonPath('data.catalog', 'admission_application_statuses')
            ->assertJsonPath('data.mapped', 2)
            ->assertJsonPath('data.unmatched.0.fis_name', 'Такого статуса в портале нет');

        $registered = $this->referenceItemId('admission_application_statuses', 'registered');
        $this->assertSame('2', FisExternalMapping::query()
            ->where('entity_type', ReferenceItem::class)
            ->where('entity_id', $registered)
            ->where('external_type', 'fis:ApplicationStatusID')
            ->value('external_id'));
    }

    public function test_preview_shows_the_plan_and_writes_nothing(): void
    {
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWith(['fis.outbound.view']));

        $this->post('/api/fis/dictionaries/preview', ['file' => $this->file($this->directionsXml())])
            ->assertOk()
            ->assertJsonPath('data.kind', 'directions')
            ->assertJsonPath('data.dictionary.item_count', 2)
            ->assertJsonCount(2, 'data.will_create');

        $this->assertSame(0, Specialty::query()->count());
        $this->assertSame(0, FisExternalMapping::query()->count());
    }

    public function test_dictionary_list_is_read_but_cannot_be_applied(): void
    {
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWith(['fis.outbound.view', 'fis.settings.manage']));
        $list = $this->file('<?xml version="1.0" encoding="UTF-8"?><Dictionaries><Dictionary><Code>4</Code><Name>Статусы заявлений</Name></Dictionary></Dictionaries>');

        $this->post('/api/fis/dictionaries/preview', ['file' => $list])
            ->assertOk()
            ->assertJsonPath('data.kind', 'dictionary_list')
            ->assertJsonPath('data.dictionaries.0.code', '4');

        $this->post('/api/fis/dictionaries/apply', ['file' => $this->file('<?xml version="1.0" encoding="UTF-8"?><Dictionaries><Dictionary><Code>4</Code><Name>Статусы</Name></Dictionary></Dictionaries>')])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Список справочников применять некуда: это оглавление, а не состав справочника. Загрузите ответ метода получения элементов справочника.');
    }

    public function test_refusal_from_fis_is_shown_as_is(): void
    {
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWith(['fis.outbound.view']));
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Dictionaries><Error><ErrorCode>17</ErrorCode><ErrorText>Пользователь не найден</ErrorText></Error></Dictionaries>';

        $this->post('/api/fis/dictionaries/preview', ['file' => $this->file($xml)])
            ->assertStatus(409)
            ->assertJsonPath('message', 'ФИС отказала: [17] Пользователь не найден');
    }

    public function test_unknown_dictionary_needs_an_explicit_target(): void
    {
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWith(['fis.outbound.view', 'fis.settings.manage']));
        $xml = '<?xml version="1.0" encoding="UTF-8"?><DictionaryData><Code>777</Code><Name>Неизвестный справочник</Name><DictionaryItems><DictionaryItem><ID>1</ID><Name>Что-то</Name></DictionaryItem></DictionaryItems></DictionaryData>';

        $this->post('/api/fis/dictionaries/apply', ['file' => $this->file($xml)])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Непонятно, куда класть справочник «Неизвестный справочник»: укажите справочник портала и имя справочника ФИС явно.');
    }

    public function test_apply_requires_its_own_permission(): void
    {
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWith(['fis.outbound.view']));

        $this->post('/api/fis/dictionaries/apply', ['file' => $this->file($this->directionsXml())])
            ->assertForbidden();
    }

    private function directionsXml(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DictionaryData>
          <Code>3</Code>
          <Name>Направления подготовки</Name>
          <DictionaryItems>
            <DictionaryItem>
              <DirectionID>1234</DirectionID>
              <Name>Инструментальное исполнительство</Name>
              <NewCode>53.02.03</NewCode>
              <QualificationCode>51</QualificationCode>
              <UGSCode>53.00.00</UGSCode>
              <UGSName>Музыкальное искусство</UGSName>
              <ParentDirectionID>53</ParentDirectionID>
            </DictionaryItem>
            <DictionaryItem>
              <DirectionID>1235</DirectionID>
              <Name>Вокальное искусство</Name>
              <NewCode>53.02.04</NewCode>
              <UGSCode>53.00.00</UGSCode>
              <UGSName>Музыкальное искусство</UGSName>
              <ParentDirectionID>53</ParentDirectionID>
            </DictionaryItem>
          </DictionaryItems>
        </DictionaryData>
        XML;
    }

    private function statusesXml(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <DictionaryData>
          <Code>4</Code>
          <Name>Статусы заявлений</Name>
          <DictionaryItems>
            <DictionaryItem><ID>2</ID><Name>Зарегистрировано</Name></DictionaryItem>
            <DictionaryItem><ID>11</ID><Name>зачислено</Name></DictionaryItem>
            <DictionaryItem><ID>99</ID><Name>Такого статуса в портале нет</Name></DictionaryItem>
          </DictionaryItems>
        </DictionaryData>
        XML;
    }

    private function file(string $xml): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('dictionary.xml', $xml);
    }

    private function referenceItemId(string $catalogCode, string $itemCode): int
    {
        $catalog = ReferenceCatalog::query()->where('code', $catalogCode)->firstOrFail();

        return ReferenceItem::query()->where('catalog_id', $catalog->id)->where('code', $itemCode)->firstOrFail()->id;
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'FIS dict '.substr(md5(json_encode($permissions)), 0, 8), 'code' => 'fis_dict_'.md5(json_encode($permissions)), 'description' => 'Test role']);
        foreach ($permissions as $code) {
            $permission = Permission::firstOrCreate(['code' => $code], ['name' => $code, 'module' => 'Test', 'description' => $code, 'system' => true, 'active' => true]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role->id);

        return $user;
    }
}
