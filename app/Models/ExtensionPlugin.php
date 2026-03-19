<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class ExtensionPlugin extends Model
{
    use HasFactory;

    protected $fillable = [
        'plugin_id',
        'name',
        'version',
        'api_version',
        'description',
        'entrypoint',
        'class_name',
        'capabilities',
        'hooks',
        'actions',
        'settings_schema',
        'settings',
        'data_ownership',
        'source_type',
        'path',
        'available',
        'enabled',
        'installation_status',
        'last_cleanup_mode',
        'validation_status',
        'validation_errors',
        'last_discovered_at',
        'last_validated_at',
        'uninstalled_at',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'hooks' => 'array',
        'actions' => 'array',
        'settings_schema' => 'array',
        'settings' => 'array',
        'data_ownership' => 'array',
        'validation_errors' => 'array',
        'available' => 'boolean',
        'enabled' => 'boolean',
        'last_discovered_at' => 'datetime',
        'last_validated_at' => 'datetime',
        'uninstalled_at' => 'datetime',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(ExtensionPluginRun::class)->latest();
    }

    public function logs(): HasManyThrough
    {
        return $this->hasManyThrough(
            ExtensionPluginRunLog::class,
            ExtensionPluginRun::class,
            'extension_plugin_id',
            'extension_plugin_run_id',
            'id',
            'id',
        )->latest('extension_plugin_run_logs.created_at');
    }

    public function getActionDefinition(string $actionId): ?array
    {
        foreach ($this->actions ?? [] as $action) {
            if (($action['id'] ?? null) === $actionId) {
                return $action;
            }
        }

        return null;
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    public function isInstalled(): bool
    {
        return ($this->installation_status ?? 'installed') === 'installed';
    }

    public function defaultCleanupMode(): string
    {
        return data_get($this->data_ownership ?? [], 'default_cleanup_policy', 'preserve');
    }

    public function hasActiveRuns(): bool
    {
        return $this->runs()
            ->where('status', 'running')
            ->exists();
    }
}
