<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VideoShot extends Model
{
    use HasUuids;

    // Shot là business object; prompt chỉ là compiled artifact (ADR v1.1)
    protected $fillable = ['session_id', 'beat', 'shot_code', 'shot_type', 'kind', 'spec_json',
        'compiled_prompt', 'negative_prompt', 'render_plan', 'status', 'review_note',
        'preview_path', 'artifact_path', 'cost_estimate', 'approved_at', 'worker_id',
        'claim_token', 'claimed_at', 'lease_expires_at', 'plan_revision',
        'scene_id', 'scene_sequence_index', 'from_state_id', 'to_state_id',
        'transition_id', 'scene_status', 'scene_image_render_id', 'video_render_id',
        'scene_projection_hash', 'motion_spec_hash', 'state_committed_at',
        'approved_qa_report_id', 'approved_artifact_hash', 'approved_by_admin_id'];

    protected $casts = [
        'spec_json' => 'array',
        'render_plan' => 'array',
        'scene_sequence_index' => 'integer',
        'approved_at' => 'datetime',
        'claimed_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'state_committed_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(VideoSession::class, 'session_id');
    }

    // `artifact_path` trên shot là CON TRỎ tới bản render mới nhất; lịch sử đầy đủ
    // (prompt đã gửi, model, ảnh nguồn, tiền thật) nằm ở đây và không bị ghi đè.
    public function renders()
    {
        return $this->hasMany(VideoRender::class, 'shot_id')->orderBy('attempt_no');
    }

    // Clip dang duoc tinh la cua shot nay. `renders()` la lich su, cai nay la con tro.
    public function videoRender()
    {
        return $this->belongsTo(VideoRender::class, 'video_render_id');
    }

    public function latestRender()
    {
        return $this->hasOne(VideoRender::class, 'shot_id')->latestOfMany('attempt_no');
    }
}
