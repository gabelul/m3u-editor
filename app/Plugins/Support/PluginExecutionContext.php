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

    public function log(string $message, string $level = 'info', array $context = []): void
    {
        $this->run->logs()->create([
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ]);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log($message, 'info', $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log($message, 'warning', $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log($message, 'error', $context);
    }
}
