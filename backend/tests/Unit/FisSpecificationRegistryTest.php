<?php

namespace Tests\Unit;

use App\Services\FisIntegration\FisSpecificationRegistry;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FisSpecificationRegistryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/fis-spec-registry-'.uniqid());
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_inventory_reports_real_xsd_hash_type_and_does_not_require_wsdl_or_disco_for_xml_http(): void
    {
        $xsd = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
  <xs:element name="PackageData" type="xs:string"/>
</xs:schema>
XML;
        $xsdPath = $this->root.'/import-service-4.9.xsd';
        File::put($xsdPath, $xsd);
        File::put($this->root.'/manifest.json', json_encode([
            'version' => '4.9',
            'files' => [['name' => basename($xsdPath), 'sha256' => hash('sha256', $xsd)]],
        ], JSON_PRETTY_PRINT));
        config([
            'fis_api.spec_registry_path' => $this->root,
            'fis_api.spec_manifest_path' => $this->root.'/manifest.json',
            'fis_api.xsd_path' => $xsdPath,
            'fis_api.wsdl_path' => null,
            'fis_api.disco_path' => null,
            'fis_api.contract_verified' => false,
        ]);

        $inventory = app(FisSpecificationRegistry::class)->inventory();

        $this->assertSame(['wsdl' => 0, 'xsd' => 1, 'disco' => 0], $inventory['counts']);
        $this->assertSame('xsd', $inventory['files'][0]['type']);
        $this->assertSame(hash('sha256', $xsd), $inventory['files'][0]['sha256']);
        $this->assertTrue($inventory['files'][0]['active']);
        $this->assertTrue($inventory['files'][0]['manifest_match']);
        $this->assertNotContains('official_wsdl_missing', $inventory['bundle']['blockers']);
        $this->assertNotContains('official_disco_missing', $inventory['bundle']['blockers']);
        $this->assertContains('contract_not_approved', $inventory['bundle']['blockers']);
        $this->assertFalse($inventory['bundle']['verified']);
    }
}
