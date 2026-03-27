<?php

namespace AppLocalPlugins\ChannelNormalizer;

use App\Plugins\Contracts\ChannelProcessorPluginInterface;
use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Contracts\LifecyclePluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use App\Plugins\Support\PluginUninstallContext;

class Plugin implements PluginInterface, ChannelProcessorPluginInterface, HookablePluginInterface, LifecyclePluginInterface
{
    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return match ($action) {
            'health_check' => PluginActionResult::success('Health check completed for Channel Normalizer.', [
                'plugin_id' => 'channel-normalizer',
                'action' => 'health_check',
                'received_payload' => $payload,
                'timestamp' => now()->toIso8601String(),
            ]),
            default => PluginActionResult::failure("Unsupported action [{$action}]"),
        };
    }
    public function runHook(string $hook, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return PluginActionResult::success("Hook [{$hook}] received by {{ display_name }}.", [
            'plugin_id' => '{{ plugin_id }}',
            'hook' => $hook,
            'payload' => $payload,
        ]);
    }
    public function uninstall(PluginUninstallContext $context): void
    {
        if (! $context->shouldPurge()) {
            return;
        }

        // Add non-declarative purge cleanup here if the plugin ever needs it.
    }
}
