<?php

namespace App\Services\Documents;

use App\Models\GeneratedDocument;
use Illuminate\Support\Str;

class DocumentVerificationService
{
    public function issueToken(): array
    {
        $token = Str::random(48);

        return [
            'token' => $token,
            'hash' => hash('sha256', $token),
            'public_id' => (string) Str::uuid(),
        ];
    }

    public function publicPayload(GeneratedDocument $document): array
    {
        $snapshot = $document->payload_snapshot ?? [];
        $studentName = (string) data_get($snapshot, 'student.full_name', '');

        return [
            'type' => $document->type?->name,
            'registration_number' => $document->registration_number,
            'issue_date' => $document->issue_date?->format('d.m.Y'),
            'subject' => $this->maskName($studentName),
            'organization' => data_get($snapshot, 'organization.short_name') ?: data_get($snapshot, 'organization.full_name'),
            'status' => match ($document->status) {
                'issued' => 'действителен',
                'cancelled' => 'отменен',
                'superseded' => 'заменен',
                default => 'не выдан',
            },
            'checked_at' => now()->format('d.m.Y H:i'),
        ];
    }

    private function maskName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) === 0 || $parts[0] === '') {
            return '';
        }

        $initials = collect(array_slice($parts, 1))
            ->filter()
            ->map(fn (string $part) => mb_substr($part, 0, 1).'.')
            ->implode(' ');

        return trim($parts[0].' '.$initials);
    }
}
