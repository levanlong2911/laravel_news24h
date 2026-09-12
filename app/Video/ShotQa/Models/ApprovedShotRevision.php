<?php

declare(strict_types=1);

namespace App\Video\ShotQa\Models;

use App\Models\VideoShot;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ApprovedShotRevision extends Model
{
    use HasUuids;

    protected $fillable = [
        'video_shot_id',
        'shot_code',
        'revision',
        'canonical_hash',
        'identity_lock_hash',
        'render_plan_hash',
        'scene_projection_hash',
        'scene_state_graph_hash',
        'motion_spec_hash',
        'render_request_hash',
        'artifact_hash',
        'qa_report_hash',
        'provider',
        'provider_model',
        'status',
        'frozen_at',
    ];

    protected $casts = [
        'revision' => 'integer',
        'frozen_at' => 'immutable_datetime',
    ];

    public function shot()
    {
        return $this->belongsTo(VideoShot::class, 'video_shot_id');
    }
}
