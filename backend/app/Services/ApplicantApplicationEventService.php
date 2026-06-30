<?php

namespace App\Services;

use App\Models\ApplicantApplication;

class ApplicantApplicationEventService
{
    public function record(
        ApplicantApplication $application,
        string $type,
        string $title,
        ?string $description = null,
        ?array $meta = null,
    ): void {
        $application->events()->create([
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'meta' => $meta,
        ]);
    }
}
