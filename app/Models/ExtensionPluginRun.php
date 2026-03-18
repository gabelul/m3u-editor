<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtensionPluginRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'extension_plugin_id',
        'user_id',
        'trigger',
        'invocation_type',
        'action',
        'hook',
        'dry_run',
        'status',
        'payload',
        'result',
        'summary',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'dry_run' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(ExtensionPlugin::class, 'extension_plugin_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
