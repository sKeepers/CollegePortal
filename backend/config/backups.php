<?php

return [
    'postgresql' => [
        // This path is inside Laravel's non-public storage directory.
        'path' => storage_path('app/private/postgresql-backups'),
        'timeout' => (int) env('POSTGRES_BACKUP_TIMEOUT', 1800),
    ],
];
