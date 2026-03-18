<?php

namespace App\Plugins;

use App\Models\ExtensionPlugin;
use App\Models\ExtensionPluginRun;
use App\Models\User;
use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Contracts\ScheduledPluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class PluginManager
{
    public function __construct(
        private readonly PluginValidator $validator,
        private readonly PluginSchemaMapper $schemaMapper,
    ) {}

    public function discover(): array
    {
        $discovered = [];
        $seenPaths = [];

        foreach ($this->pluginPaths() as $pluginPath) {
            $result = $this->validator->validatePath($pluginPath);
            $manifest = $result->manifest;
            $pluginId = $result->pluginId ?? basename($pluginPath);

            $record = ExtensionPlugin::query()->firstOrNew(['plugin_id' => $pluginId]);
            $record->fill([
                'name' => $manifest?->name ?? Arr::get($result->manifestData, 'name', $pluginId),
                'version' => $manifest?->version,
                'api_version' => $manifest?->apiVersion ?? Arr::get($result->manifestData, 'api_version'),
                'description' => $manifest?->description ?? Arr::get($result->manifestData, 'description'),
                'entrypoint' => $manifest?->entrypoint ?? Arr::get($result->manifestData, 'entrypoint'),
                'class_name' => $manifest?->className ?? Arr::get($result->manifestData, 'class'),
                'capabilities' => $manifest?->capabilities ?? Arr::get($result->manifestData, 'capabilities', []),
                'hooks' => $manifest?->hooks ?? Arr::get($result->manifestData, 'hooks', []),
                'actions' => $manifest?->actions ?? Arr::get($result->manifestData, 'actions', []),
                'settings_schema' => $manifest?->settings ?? Arr::get($result->manifestData, 'settings', []),
                'path' => $pluginPath,
                'source_type' => 'local',
                'available' => true,
                'validation_status' => $result->valid ? 'valid' : 'invalid',
                'validation_errors' => $result->errors,
                'last_discovered_at' => now(),
                'last_validated_at' => now(),
            ]);
            $record->save();

            $seenPaths[] = $pluginPath;
            $discovered[] = $record->fresh();
        }

        if ($seenPaths !== []) {
            ExtensionPlugin::query()
                ->whereNotIn('path', $seenPaths)
                ->update(['available' => false]);
        }

        return $discovered;
    }

    public function validate(ExtensionPlugin $plugin): ExtensionPlugin
    {
        $result = $this->validator->validatePath((string) $plugin->path);

        $plugin->update([
            'name' => $result->manifest?->name ?? $plugin->name,
            'version' => $result->manifest?->version ?? $plugin->version,
            'api_version' => $result->manifest?->apiVersion ?? $plugin->api_version,
            'description' => $result->manifest?->description ?? $plugin->description,
            'entrypoint' => $result->manifest?->entrypoint ?? $plugin->entrypoint,
            'class_name' => $result->manifest?->className ?? $plugin->class_name,
            'capabilities' => $result->manifest?->capabilities ?? $plugin->capabilities,
            'hooks' => $result->manifest?->hooks ?? $plugin->hooks,
            'actions' => $result->manifest?->actions ?? $plugin->actions,
            'settings_schema' => $result->manifest?->settings ?? $plugin->settings_schema,
            'validation_status' => $result->valid ? 'valid' : 'invalid',
            'validation_errors' => $result->errors,
            'last_validated_at' => now(),
            'available' => file_exists((string) $plugin->path),
        ]);

        return $plugin->fresh();
    }

    public function resolvedSettings(ExtensionPlugin $plugin): array
    {
        return $this->schemaMapper->defaultsForFields(
            $plugin->settings_schema ?? [],
            $plugin->settings ?? [],
        );
    }

    public function updateSettings(ExtensionPlugin $plugin, array $settings): ExtensionPlugin
    {
        Validator::make(
            ['settings' => $settings],
            $this->schemaMapper->settingsRules($plugin),
        )->validate();

        $plugin->update([
            'settings' => $this->resolvedSettings($plugin) + $settings,
        ]);

        return $plugin->fresh();
    }

    public function executeAction(
        ExtensionPlugin $plugin,
        string $action,
        array $payload = [],
        array $options = [],
    ): ExtensionPluginRun {
        $run = $this->startRun($plugin, [
            'trigger' => $options['trigger'] ?? 'manual',
            'invocation_type' => 'action',
            'action' => $action,
            'payload' => $payload,
            'dry_run' => (bool) ($options['dry_run'] ?? false),
            'user_id' => $options['user_id'] ?? null,
        ]);

        try {
            Validator::make($payload, $this->schemaMapper->actionRules($plugin, $action))->validate();

            $instance = $this->instantiate($plugin);
            $context = new PluginExecutionContext(
                plugin: $plugin,
                run: $run,
                trigger: (string) ($options['trigger'] ?? 'manual'),
                dryRun: (bool) ($options['dry_run'] ?? false),
                hook: null,
                user: isset($options['user_id']) ? User::find($options['user_id']) : null,
                settings: $this->resolvedSettings($plugin),
            );

            $result = $instance->runAction($action, $payload, $context);

            return $this->finishRun($run, $result);
        } catch (Throwable $exception) {
            return $this->failRun($run, $exception->getMessage());
        }
    }

    public function executeHook(
        ExtensionPlugin $plugin,
        string $hook,
        array $payload = [],
        array $options = [],
    ): ExtensionPluginRun {
        $run = $this->startRun($plugin, [
            'trigger' => $options['trigger'] ?? 'hook',
            'invocation_type' => 'hook',
            'hook' => $hook,
            'payload' => $payload,
            'dry_run' => (bool) ($options['dry_run'] ?? true),
            'user_id' => $options['user_id'] ?? null,
        ]);

        try {
            $instance = $this->instantiate($plugin);
            if (! $instance instanceof HookablePluginInterface) {
                throw new RuntimeException("Plugin [{$plugin->plugin_id}] does not implement hook handling.");
            }

            $context = new PluginExecutionContext(
                plugin: $plugin,
                run: $run,
                trigger: (string) ($options['trigger'] ?? 'hook'),
                dryRun: (bool) ($options['dry_run'] ?? true),
                hook: $hook,
                user: isset($options['user_id']) ? User::find($options['user_id']) : null,
                settings: $this->resolvedSettings($plugin),
            );

            $result = $instance->runHook($hook, $payload, $context);

            return $this->finishRun($run, $result);
        } catch (Throwable $exception) {
            return $this->failRun($run, $exception->getMessage());
        }
    }

    public function scheduledInvocations(ExtensionPlugin $plugin, CarbonInterface $now): array
    {
        $instance = $this->instantiate($plugin);
        if (! $instance instanceof ScheduledPluginInterface) {
            return [];
        }

        return $instance->scheduledActions($now, $this->resolvedSettings($plugin));
    }

    public function enabledPluginsForHook(string $hook)
    {
        return ExtensionPlugin::query()
            ->where('enabled', true)
            ->where('available', true)
            ->where('validation_status', 'valid')
            ->get()
            ->filter(fn (ExtensionPlugin $plugin) => in_array($hook, $plugin->hooks ?? [], true))
            ->values();
    }

    public function instantiate(ExtensionPlugin $plugin): PluginInterface
    {
        $plugin = $this->validate($plugin);

        if ($plugin->validation_status !== 'valid') {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] is not valid.");
        }

        $entrypoint = rtrim((string) $plugin->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$plugin->entrypoint;
        require_once $entrypoint;

        $instance = app($plugin->class_name);
        if (! $instance instanceof PluginInterface) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] did not resolve to a valid plugin instance.");
        }

        return $instance;
    }

    private function pluginPaths(): array
    {
        $paths = [];

        foreach (config('plugins.directories', []) as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $pluginPath) {
                if (file_exists($pluginPath.DIRECTORY_SEPARATOR.'plugin.json')) {
                    $paths[] = $pluginPath;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    private function startRun(ExtensionPlugin $plugin, array $attributes): ExtensionPluginRun
    {
        return $plugin->runs()->create([
            ...$attributes,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    private function finishRun(ExtensionPluginRun $run, PluginActionResult $result): ExtensionPluginRun
    {
        $run->update([
            'status' => $result->success ? 'completed' : 'failed',
            'result' => $result->toArray(),
            'summary' => $result->summary,
            'finished_at' => now(),
        ]);

        return $run->fresh();
    }

    private function failRun(ExtensionPluginRun $run, string $message): ExtensionPluginRun
    {
        $run->update([
            'status' => 'failed',
            'summary' => $message,
            'result' => [
                'success' => false,
                'summary' => $message,
                'data' => [],
            ],
            'finished_at' => now(),
        ]);

        return $run->fresh();
    }
}
