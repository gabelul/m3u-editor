<?php

namespace App\Plugins\Contracts;

use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;

interface HookablePluginInterface
{
    public function runHook(string $hook, array $payload, PluginExecutionContext $context): PluginActionResult;
}
