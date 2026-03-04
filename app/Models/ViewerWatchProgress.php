<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ViewerWatchProgress extends Model
{
    use HasFactory;

    protected $table = 'viewer_watch_progress';

    protected $casts = [
        'completed' => 'boolean',
        'last_watched_at' => 'datetime',
        'position_seconds' => 'integer',
        'duration_seconds' => 'integer',
        'watch_count' => 'integer',
        'stream_id' => 'integer',
        'series_id' => 'integer',
        'season_number' => 'integer',
    ];

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(PlaylistViewer::class, 'playlist_viewer_id');
    }

    public function scopeLive($query)
    {
        return $query->where('content_type', 'live');
    }

    public function scopeVod($query)
    {
        return $query->where('content_type', 'vod');
    }

    public function scopeEpisode($query)
    {
        return $query->where('content_type', 'episode');
    }

    public function scopeCompleted($query)
    {
        return $query->where('completed', true);
    }
}
