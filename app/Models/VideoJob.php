<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoJob extends Model
{
    use HasUuids;

    protected $fillable = [
        'story_plan_id', 'part_number', 'status', 'script_json',
        'claimed_by', 'claimed_at', 'cost_total',
        'video_path', 'thumbnail_path', 'youtube_video_id', 'facebook_post_id',
        'error_message',
    ];

    protected $casts = [
        'script_json' => 'array',
        'claimed_at' => 'datetime',
        'cost_total' => 'float',
    ];

    public function storyPlan(): BelongsTo
    {
        return $this->belongsTo(StoryPlan::class);
    }

    public function scopeClaimable($query)
    {
        return $query->where('status', 'script_ready');
    }
}
