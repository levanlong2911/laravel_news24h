<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoAnalytic extends Model
{
    use HasUuids;

    protected $fillable = [
        'video_job_id', 'platform', 'date',
        'views', 'watch_time_seconds', 'avg_view_duration', 'retention_rate', 'ctr',
        'likes', 'comments', 'shares', 'saves', 'raw_payload',
    ];

    protected $casts = [
        'date'        => 'date',
        'raw_payload' => 'array',
        'ctr'         => 'float',
        'retention_rate' => 'float',
    ];

    public function videoJob(): BelongsTo
    {
        return $this->belongsTo(VideoJob::class);
    }
}
