<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VideoRenderScene extends Model
{
    use HasUuids;

    protected $fillable = [
        'render_plan_id', 'project_id', 'revision',
        'scene_index', 'scene_code', 'scene_type', 'milestone_keys', 'basis', 'title', 'purpose',
        'duration_ms', 'continuity_from_scene_id', 'state_json',
        'delta_prompt', 'prompt_version', 'transition_mode', 'references_json',
        'reference_manifest_hash', 'video_plan_json', 'design_image_id',
        'continuity_group', 'source_scene_code', 'camera_change_reason',
    ];

    protected $casts = [
        'revision' => 'integer',
        'scene_index' => 'integer',
        'duration_ms' => 'integer',
        'state_json' => 'array',
        'references_json' => 'array',
        'milestone_keys' => 'array',
        'video_plan_json' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(VideoProject::class, 'project_id');
    }

    public function renderPlan()
    {
        return $this->belongsTo(VideoRenderPlan::class, 'render_plan_id');
    }

    public function designImage()
    {
        return $this->belongsTo(VideoDesignImage::class, 'design_image_id');
    }

    public function continuityFrom()
    {
        return $this->belongsTo(self::class, 'continuity_from_scene_id');
    }

    public function continuesInto()
    {
        return $this->hasMany(self::class, 'continuity_from_scene_id');
    }
}
