<?php

namespace App\Plugins\Support;

use App\Models\ExtensionPlugin;
use App\Models\User;

class PluginUninstallContext
{
    public function __construct(
        public readonly ExtensionPlugin $plugin,
        public readonly string $cleanupMode,
        public readonly array $dataOwnership,
        public readonly ?User $user = null,
    ) {}

    public function shouldPurge(): bool
    {
        return $this->cleanupMode === 'purge';
    }
}
