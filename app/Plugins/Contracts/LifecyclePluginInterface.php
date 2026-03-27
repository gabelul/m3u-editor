<?php

namespace App\Plugins\Contracts;

use App\Plugins\Support\PluginUninstallContext;

interface LifecyclePluginInterface
{
    public function uninstall(PluginUninstallContext $context): void;
}
