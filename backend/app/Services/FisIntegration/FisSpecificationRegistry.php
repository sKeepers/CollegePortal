<?php

namespace App\Services\FisIntegration;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class FisSpecificationRegistry
{
    public function __construct(private ?FisWsdlAnalyzer $analyzer = null)
    {
        $this->analyzer ??= new FisWsdlAnalyzer();
    }

    public function manifest(): array
    {
        $path = config('fis_api.spec_manifest_path');
        if ($path && is_file($path) && is_readable($path)) {
            $json = json_decode((string) file_get_contents($path), true);
            return is_array($json) ? $json : [];
        }

        return ['status' => 'missing', 'message' => 'Official FIS specification manifest is not loaded.'];
    }

    public function xsdPath(): ?string
    {
        return $this->activePath('xsd_path');
    }

    public function wsdlPath(): ?string
    {
        return $this->activePath('wsdl_path');
    }

    public function discoPath(): ?string
    {
        return $this->activePath('disco_path');
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

    public function inventory(): array
    {
        $root = (string) config('fis_api.spec_registry_path');
        $rootReal = $root !== '' && is_dir($root) ? realpath($root) : false;
        $manifestPath = (string) config('fis_api.spec_manifest_path');
        $manifestReadable = $manifestPath !== '' && is_file($manifestPath) && is_readable($manifestPath);
        $manifest = $this->manifest();
        $files = [];

        if ($rootReal) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->isLink()) {
                    continue;
                }

                $type = $this->contractType($file->getFilename());
                if ($type === null) {
                    continue;
                }

                $path = $file->getPathname();
                $relativePath = ltrim(str_replace('\\', '/', substr($path, strlen($rootReal))), '/');
                $readable = is_readable($path);
                $sha256 = $readable ? hash_file('sha256', $path) : null;
                $manifestEntry = $readable ? $this->manifestEntry($manifest, $manifestPath, $path) : null;
                $summary = $readable ? $this->contractSummary($type, $path) : ['parse_status' => 'unreadable'];

                $files[] = [
                    'path' => $relativePath,
                    'type' => $type,
                    'mime_type' => $readable ? $this->mimeType($path) : null,
                    'size_bytes' => $file->getSize(),
                    'sha256' => $sha256,
                    'readable' => $readable,
                    'active' => $this->isActivePath($path),
                    'manifest_listed' => $manifestEntry !== null,
                    'manifest_match' => $sha256 !== null && $manifestEntry !== null
                        && filled($manifestEntry['sha256'] ?? null)
                        && hash_equals(strtolower((string) $manifestEntry['sha256']), strtolower($sha256)),
                    'summary' => $summary,
                ];
            }
        }

        usort($files, fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
        $bundle = $this->bundleState($files);

        return [
            'status' => $rootReal ? 'loaded' : 'missing',
            'root' => $rootReal ? basename($rootReal) : null,
            'manifest' => [
                'status' => $manifestReadable ? 'loaded' : (is_file($manifestPath) ? 'unreadable' : 'missing'),
                'sha256' => $manifestReadable ? hash_file('sha256', $manifestPath) : null,
                'version' => $manifest['version'] ?? null,
                'generated_at' => $manifest['generated_at'] ?? null,
            ],
            'counts' => [
                'wsdl' => collect($files)->where('type', 'wsdl')->count(),
                'xsd' => collect($files)->where('type', 'xsd')->count(),
                'disco' => collect($files)->where('type', 'disco')->count(),
            ],
            'files' => $files,
            'bundle' => $bundle,
        ];
    }

    private function activePath(string $key): ?string
    {
        $path = config('fis_api.'.$key);

        return $path && is_file($path) && is_readable($path) ? $path : null;
    }

    private function contractType(string $filename): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === 'wsdl') {
            return 'wsdl';
        }
        if ($extension === 'xsd') {
            return 'xsd';
        }
        if ($extension === 'disco' || str_ends_with(strtolower($filename), '.disco.xml')) {
            return 'disco';
        }

        return null;
    }

    private function contractSummary(string $type, string $path): array
    {
        $analysis = match ($type) {
            'wsdl' => $this->analyzer->analyze($path),
            'xsd' => $this->analyzer->analyze(null, $path),
            'disco' => $this->analyzer->analyze(null, null, $path),
        };

        if ($type === 'wsdl') {
            $bindings = $analysis['bindings'] ?? [];
            $operations = $analysis['operations'] ?? [];

            return [
                'parse_status' => $analysis['status'] ?? 'missing',
                'target_namespace' => $analysis['target_namespace'] ?? null,
                'soap_versions' => $analysis['soap_versions'] ?? [],
                'bindings' => count($bindings),
                'ports' => collect($analysis['services'] ?? [])->sum(fn (array $service): int => count($service['ports'] ?? [])),
                'operations' => count($operations),
                'soap_actions' => collect($operations)->sum(fn (array $operation): int => collect($operation['bindings'] ?? [])->whereNotNull('soap_action')->count()),
            ];
        }

        if ($type === 'xsd') {
            return [
                'parse_status' => $analysis['xsd']['status'] ?? 'missing',
                'target_namespace' => $analysis['xsd']['target_namespace'] ?? null,
                'root_elements' => $analysis['xsd']['root_elements'] ?? [],
                'imports' => count($analysis['xsd']['imports'] ?? []),
                'bindings' => 0,
                'ports' => 0,
                'operations' => 0,
                'soap_actions' => 0,
            ];
        }

        return [
            'parse_status' => $analysis['disco']['status'] ?? 'missing',
            'bindings' => 0,
            'ports' => 0,
            'operations' => 0,
            'soap_actions' => 0,
        ];
    }

    private function bundleState(array $files): array
    {
        $xsd = collect($files)->where('type', 'xsd');
        $blockers = [];

        if ($xsd->isEmpty()) {
            $blockers[] = 'official_xsd_missing';
        }
        if ($xsd->isNotEmpty() && ! $xsd->contains('active', true)) {
            $blockers[] = 'active_xsd_missing';
        }
        if (collect($files)->contains(fn (array $file): bool => ! $file['manifest_listed'] || ! $file['manifest_match'])) {
            $blockers[] = 'manifest_integrity_incomplete';
        }
        if (collect($files)->contains(fn (array $file): bool => ! $file['readable'])) {
            $blockers[] = 'contract_file_unreadable';
        }

        $complete = $blockers === [];
        $verified = $complete && (bool) config('fis_api.contract_verified', false);
        if ($complete && ! $verified) {
            $blockers[] = 'contract_not_approved';
        }

        return [
            'complete' => $complete,
            'verified' => $verified,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }
    private function manifestEntry(array $manifest, string $manifestPath, string $path): ?array
    {
        if (! is_file($manifestPath)) {
            return null;
        }

        $manifestRoot = realpath(dirname($manifestPath));
        $realPath = realpath($path);
        if (! $manifestRoot || ! $realPath || ! str_starts_with($realPath, $manifestRoot.DIRECTORY_SEPARATOR)) {
            return null;
        }

        $name = str_replace('\\', '/', substr($realPath, strlen($manifestRoot) + 1));

        return collect($manifest['files'] ?? [])->first(function (array $entry) use ($name): bool {
            return ($entry['name'] ?? $entry['logical_name'] ?? null) === $name;
        });
    }

    private function isActivePath(string $path): bool
    {
        $realPath = realpath($path);

        return collect([$this->wsdlPath(), $this->xsdPath(), $this->discoPath()])
            ->filter()
            ->contains(fn (string $active): bool => realpath($active) === $realPath);
    }

    private function mimeType(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mime = finfo_file($finfo, $path) ?: 'application/octet-stream';
        finfo_close($finfo);

        return $mime;
    }
}
