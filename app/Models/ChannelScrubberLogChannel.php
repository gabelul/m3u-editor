<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelScrubberLogChannel extends Model
{
    public function channelScrubberLog(): BelongsTo
    {
        return $this->belongsTo(ChannelScrubberLog::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
