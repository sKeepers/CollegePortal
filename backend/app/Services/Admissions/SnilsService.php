<?php

namespace App\Services\Admissions;

use App\Models\Person;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Validation\ValidationException;

class SnilsService
{
    public function __construct(private readonly DocumentMaskingService $masking)
    {
    }

    public function normalize(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) !== 11) {
            throw ValidationException::withMessages(['snils' => 'СНИЛС должен содержать 11 цифр.']);
        }

        if (! $this->checksumValid($digits)) {
            throw ValidationException::withMessages(['snils' => 'Контрольное число СНИЛС не совпадает.']);
        }

        return substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6, 3).' '.substr($digits, 9, 2);
    }

    public function hash(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits === '' ? null : hash('sha256', $digits);
    }

    public function update(Person $person, ?string $snils, ?User $actor = null): Person
    {
        $normalized = $this->normalize($snils);
        $hash = $this->hash($normalized);

        if ($hash !== null && Person::query()->where('snils_hash', $hash)->whereKeyNot($person->id)->exists()) {
            throw ValidationException::withMessages(['snils' => 'СНИЛС уже используется другой записью Person.']);
        }

        $old = [
            'person_id' => $person->id,
            'snils_masked' => $this->masking->snils($person->snils),
            'has_snils_hash' => filled($person->snils_hash),
        ];

        $person->forceFill([
            'snils' => $normalized,
            'snils_hash' => $hash,
        ])->save();

        AuditLogService::log('Admissions', 'applicant_snils_updated', $person, $old, [
            'person_id' => $person->id,
            'snils_masked' => $this->masking->snils($normalized),
            'has_snils_hash' => filled($hash),
        ], user: $actor);

        return $person->fresh();
    }

    public function checksumValid(string $digits): bool
    {
        if (! preg_match('/^\d{11}$/', $digits)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $digits[$i]) * (9 - $i);
        }

        $check = match (true) {
            $sum < 100 => $sum,
            $sum === 100 || $sum === 101 => 0,
            default => $sum % 101,
        };

        if ($check === 100) {
            $check = 0;
        }

        return (int) substr($digits, 9, 2) === $check;
    }
}
