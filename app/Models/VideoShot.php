<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoShot extends Model
{
    use HasUuids;

    protected $fillable = [
        'scene_id', 'shot_number',
        'shot_type', 'camera_angle', 'lens', 'camera_movement',
        'subject_actor', 'subject_action', 'subject_object',
        'lighting', 'emotion', 'estimated_duration',
        'media_type', 'needs_ai_video',
        'cinematic_dsl', 'compiled_prompt', 'status',
    ];

    protected $casts = [
        'cinematic_dsl'      => 'array',
        'needs_ai_video'     => 'boolean',
        'estimated_duration' => 'float',
    ];

    public function scene(): BelongsTo
    {
        return $this->belongsTo(VideoScene::class, 'scene_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(VideoAsset::class, 'shot_id');
    }
}
