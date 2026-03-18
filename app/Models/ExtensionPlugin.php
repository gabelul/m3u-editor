<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'source_type',
        'path',
        'available',
        'enabled',
        'validation_status',
        'validation_errors',
        'last_discovered_at',
        'last_validated_at',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'hooks' => 'array',
        'actions' => 'array',
        'settings_schema' => 'array',
        'settings' => 'array',
        'validation_errors' => 'array',
        'available' => 'boolean',
        'enabled' => 'boolean',
        'last_discovered_at' => 'datetime',
        'last_validated_at' => 'datetime',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(ExtensionPluginRun::class)->latest();
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
}
