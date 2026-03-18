<?php

namespace App\Plugins\Support;

use App\Models\ExtensionPlugin;
use App\Models\ExtensionPluginRun;
use App\Models\User;

class PluginExecutionContext
{
    public function __construct(
        public readonly ExtensionPlugin $plugin,
        public readonly ExtensionPluginRun $run,
        public readonly string $trigger,
        public readonly bool $dryRun,
        public readonly ?string $hook,
        public readonly ?User $user,
        public readonly array $settings,
    ) {}
}
