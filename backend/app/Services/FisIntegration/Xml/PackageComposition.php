<?php

namespace App\Services\FisIntegration\Xml;

/**
 * Результат сборки PackageData: сам XML, счётчики по разделам и список причин,
 * по которым пакет собирать нельзя.
 */
class PackageComposition
{
    /**
     * @param  list<array{code:string,field:string,message:string,entity:?string}>  $blockers
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public readonly string $xml,
        public readonly array $blockers,
        public readonly array $counts,
    ) {
    }

    public function blocked(): bool
    {
        return $this->blockers !== [];
    }
}
