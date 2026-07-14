<?php

namespace App\Services\FisIntegration;

class FisSpecificationRegistry
{
    public function __construct(private ?FisWsdlAnalyzer $analyzer = null)
    {
        $this->analyzer ??= new FisWsdlAnalyzer();
    }

    public function manifest(): array
    {
        $path = config('fis_api.spec_manifest_path');
        if ($path && is_file($path)) {
            $json = json_decode((string) file_get_contents($path), true);
            return is_array($json) ? $json : [];
        }
        return ['status' => 'missing', 'message' => 'Official FIS specification manifest is not loaded.'];
    }

    public function xsdPath(): ?string
    {
        $path = config('fis_api.xsd_path');
        return $path && is_file($path) ? $path : null;
    }

    public function wsdlPath(): ?string
    {
        $path = config('fis_api.wsdl_path');
        return $path && is_file($path) ? $path : null;
    }

    public function discoPath(): ?string
    {
        $path = config('fis_api.disco_path');
        return $path && is_file($path) ? $path : null;
    }

    public function schemaVersion(): string
    {
        return (string) config('fis_api.schema_version', 'pending-official-spec');
    }

    public function analysis(): array
    {
        return $this->analyzer->analyze(
            $this->wsdlPath(),
            $this->xsdPath(),
            $this->discoPath(),
        );
    }
}
