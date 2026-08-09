<?php

namespace App\Services\FisIntegration;

use App\Services\FisIntegration\Xml\FisXsdSchema;
use App\Services\FisIntegration\Xml\PackageDataComposer;

class FisSpecificationRegistry
{
    public const PENDING = 'pending-official-spec';

    public function __construct(private readonly FisXsdSchema $schema)
    {
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
        return $this->schema->path();
    }

    public function schemaVersion(): string
    {
        return (string) config('fis_api.schema_version', self::PENDING);
    }

    /**
     * Официальная схема считается загруженной, когда есть и файл XSD, и версия,
     * отличная от заглушки.
     */
    public function officialSchemaLoaded(): bool
    {
        return $this->xsdPath() !== null && $this->schemaVersion() !== self::PENDING;
    }

    public function xsdFingerprint(): ?string
    {
        return $this->schema->fingerprint();
    }

    /** @return list<string> */
    public function supportedPackageTypes(): array
    {
        return PackageDataComposer::SUPPORTED_TYPES;
    }
}
