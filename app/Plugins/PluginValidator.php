<?php

namespace App\Plugins;

use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Support\PluginManifest;
use App\Plugins\Support\PluginValidationResult;
use Illuminate\Support\Str;
use Throwable;

class PluginValidator
{
    public function __construct(
        private readonly PluginManifestLoader $loader,
    ) {}

    public function validatePath(string $pluginPath): PluginValidationResult
    {
        $errors = [];
        $manifest = null;
        $manifestData = [];
        $pluginId = basename($pluginPath);

        try {
            $manifest = $this->loader->load($pluginPath);
            $manifestData = $manifest->raw;
            $pluginId = $manifest->id;
        } catch (Throwable $exception) {
            return new PluginValidationResult(false, [$exception->getMessage()], null, $manifestData, $pluginId);
        }

        foreach (['id', 'name', 'entrypoint', 'class'] as $key) {
            if (blank($manifestData[$key] ?? null)) {
                $errors[] = "Missing required manifest field [{$key}]";
            }
        }

        if (($manifestData['api_version'] ?? null) !== config('plugins.api_version')) {
            $errors[] = 'Plugin api_version does not match host plugin API version.';
        }

        $knownCapabilities = array_keys(config('plugins.capabilities', []));
        foreach ($manifest->capabilities as $capability) {
            if (! in_array($capability, $knownCapabilities, true)) {
                $errors[] = "Unknown capability [{$capability}]";
            }
        }

        $knownHooks = config('plugins.hooks', []);
        foreach ($manifest->hooks as $hook) {
            if (! is_string($hook) || ! in_array($hook, $knownHooks, true)) {
                $errors[] = "Unknown hook [{$hook}]";
            }
        }

        $fieldTypes = config('plugins.field_types', []);
        foreach ($manifest->settings as $field) {
            $errors = [...$errors, ...$this->validateFieldDefinition($field, $fieldTypes, 'settings')];
        }

        $actionIds = [];
        foreach ($manifest->actions as $action) {
            $actionId = $action['id'] ?? null;
            if (blank($actionId)) {
                $errors[] = 'Action missing required field [id]';
                continue;
            }

            if (in_array($actionId, $actionIds, true)) {
                $errors[] = "Duplicate action id [{$actionId}]";
            }

            $actionIds[] = $actionId;

            foreach ($action['fields'] ?? [] as $field) {
                $errors = [...$errors, ...$this->validateFieldDefinition($field, $fieldTypes, "actions.{$actionId}")];
            }
        }

        $errors = [...$errors, ...$this->validateDataOwnership($manifest)];

        if (! file_exists($manifest->entrypointPath())) {
            $errors[] = "Missing entrypoint file [{$manifest->entrypoint}]";
        } else {
            try {
                require_once $manifest->entrypointPath();
            } catch (Throwable $exception) {
                $errors[] = "Entrypoint failed to load: {$exception->getMessage()}";
            }
        }

        if (! class_exists($manifest->className)) {
            $errors[] = "Plugin class [{$manifest->className}] was not found.";
        } else {
            if (! is_subclass_of($manifest->className, PluginInterface::class)) {
                $errors[] = "Plugin class [{$manifest->className}] must implement ".PluginInterface::class;
            }

            foreach ($manifest->capabilities as $capability) {
                $requiredInterface = config("plugins.capabilities.{$capability}");
                if ($requiredInterface && ! is_subclass_of($manifest->className, $requiredInterface)) {
                    $errors[] = "Plugin class [{$manifest->className}] must implement [{$requiredInterface}] for capability [{$capability}]";
                }
            }

            if ($manifest->hooks !== [] && ! is_subclass_of($manifest->className, HookablePluginInterface::class)) {
                $errors[] = "Plugin class [{$manifest->className}] must implement ".HookablePluginInterface::class.' when hooks are declared.';
            }
        }

        return new PluginValidationResult($errors === [], $errors, $manifest, $manifestData, $pluginId);
    }

    private function validateFieldDefinition(array $field, array $fieldTypes, string $group): array
    {
        $errors = [];
        $fieldId = $field['id'] ?? null;

        if (blank($fieldId)) {
            return ["{$group} field is missing [id]"];
        }

        $type = $field['type'] ?? 'text';
        if (! in_array($type, $fieldTypes, true)) {
            $errors[] = "{$group}.{$fieldId} uses unsupported type [{$type}]";
        }

        if (in_array($type, ['select', 'model_select'], true) && blank($field['label'] ?? null)) {
            $errors[] = "{$group}.{$fieldId} should define a human-friendly [label]";
        }

        if ($type === 'select' && empty($field['options'])) {
            $errors[] = "{$group}.{$fieldId} select fields require [options]";
        }

        if ($type === 'model_select' && blank($field['model'] ?? null)) {
            $errors[] = "{$group}.{$fieldId} model_select fields require [model]";
        }

        return $errors;
    }

    private function validateDataOwnership(PluginManifest $manifest): array
    {
        $errors = [];
        $ownership = $manifest->dataOwnership;

        if (! in_array($ownership['default_cleanup_policy'] ?? null, config('plugins.cleanup_modes', []), true)) {
            $errors[] = 'data_ownership.default_cleanup_policy must be one of the supported cleanup modes.';
        }

        $tablePrefix = (string) ($ownership['table_prefix'] ?? '');
        foreach ($ownership['tables'] ?? [] as $table) {
            if (! Str::startsWith($table, $tablePrefix)) {
                $errors[] = "Declared table [{$table}] must start with [{$tablePrefix}] so uninstall can safely purge plugin-owned data.";
            }
        }

        $allowedRoots = collect(config('plugins.owned_storage_roots', []))
            ->map(fn (string $root) => trim($root, '/'))
            ->filter()
            ->all();

        foreach (['directories', 'files'] as $group) {
            foreach ($ownership[$group] ?? [] as $path) {
                if (Str::startsWith($path, '/') || Str::contains($path, ['..', '\\'])) {
                    $errors[] = "Declared {$group} path [{$path}] must stay inside approved storage roots.";
                    continue;
                }

                if (! collect($allowedRoots)->contains(fn (string $root) => Str::startsWith($path, $root.'/') || $path === $root)) {
                    $errors[] = "Declared {$group} path [{$path}] must start with one of: ".implode(', ', $allowedRoots);
                    continue;
                }

                if (! Str::contains($path, '/'.$manifest->id) && ! Str::contains($path, '/'.Str::of($manifest->id)->replace('-', '_')->value())) {
                    $errors[] = "Declared {$group} path [{$path}] must include the plugin id so cleanup stays namespaced.";
                }
            }
        }

        return $errors;
    }
}
