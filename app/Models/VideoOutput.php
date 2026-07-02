<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoOutput extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id', 'video_path', 'thumbnail_path', 'youtube_video_id',
        'duration_seconds', 'status', 'error_message',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'project_id');
    }
}
