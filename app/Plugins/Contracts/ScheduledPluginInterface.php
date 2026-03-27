<?php

namespace App\Plugins\Contracts;

use Carbon\CarbonInterface;

interface ScheduledPluginInterface
{
    public function scheduledActions(CarbonInterface $now, array $settings): array;
}
