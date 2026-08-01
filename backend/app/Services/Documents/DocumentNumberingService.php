<?php

namespace App\Services\Documents;

use App\Models\DocumentNumberSequence;
use App\Models\DocumentType;
use Illuminate\Support\Facades\DB;

class DocumentNumberingService
{
    public function next(DocumentType $type, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return DB::transaction(function () use ($type, $year): string {
            $sequence = DocumentNumberSequence::query()
                ->where('document_type_id', $type->id)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = DocumentNumberSequence::query()->create([
                    'document_type_id' => $type->id,
                    'year' => $year,
                    'last_number' => 0,
                ]);
            }

            $sequence->increment('last_number');
            $sequence->refresh();

            return $this->format($type->numbering_pattern, $year, (int) $sequence->last_number);
        });
    }

    private function format(string $pattern, int $year, int $number): string
    {
        return preg_replace_callback(
            '/\{NUMBER:(\d+)\}/',
            fn (array $match) => str_pad((string) $number, (int) $match[1], '0', STR_PAD_LEFT),
            str_replace('{YEAR}', (string) $year, $pattern),
        ) ?? sprintf('СПР-%d-%05d', $year, $number);
    }
}
