<?php

namespace App\Services\Access;

use App\Models\AccessEvent;

class NullAccessAttendanceBridge implements AccessAttendanceBridge
{
    public function accessEventRecorded(AccessEvent $event): void
    {
        // ACCESS-001 only exposes the integration seam; journal/attendance writes come later.
    }
}
