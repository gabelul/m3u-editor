<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtensionPluginRunLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'extension_plugin_run_id',
        'level',
        'message',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ExtensionPluginRun::class, 'extension_plugin_run_id');
    }
}
