<?php

namespace App\Plugins\Contracts;

use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;

interface PluginInterface
{
    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult;
}
