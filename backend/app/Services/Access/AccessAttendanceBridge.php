<?php

namespace App\Services\Access;

use App\Models\AccessEvent;

interface AccessAttendanceBridge
{
    public function accessEventRecorded(AccessEvent $event): void;
}
