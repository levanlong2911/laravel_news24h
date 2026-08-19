<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VideoRenderPlan extends Model
{
    use HasUuids;

    protected $fillable = [
        'session_id', 'revision', 'schema_version', 'builder_version', 'status',
        'scene_count', 'aspect_ratio', 'width', 'height', 'target_duration_ms',
        'plan_json', 'plan_hash', 'validated_at', 'frozen_at',
    ];

    protected $casts = [
        'revision' => 'integer',
        'scene_count' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'target_duration_ms' => 'integer',
        'plan_json' => 'array',
        'validated_at' => 'datetime',
        'frozen_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(VideoSession::class, 'session_id');
    }
}
