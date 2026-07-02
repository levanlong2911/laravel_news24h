<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VideoProject extends Model
{
    use HasUuids;

    protected $fillable = [
        'article_id', 'status', 'video_type', 'duration',
        'theme', 'style', 'color_palette', 'pacing',
        'emotion_arc', 'transformation_json', 'story_json', 'scene_graph_json',
        'error_message',
    ];

    protected $casts = [
        'emotion_arc'        => 'array',
        'transformation_json'=> 'array',
        'story_json'         => 'array',
        'scene_graph_json'   => 'array',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(VideoScene::class, 'project_id')->orderBy('scene_number');
    }

    public function output(): HasOne
    {
        return $this->hasOne(VideoOutput::class, 'project_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeGraphBuilt($query)
    {
        return $query->where('status', 'graph_built');
    }

    public function isFullyPlanned(): bool
    {
        return $this->status === 'graph_built';
    }
}
