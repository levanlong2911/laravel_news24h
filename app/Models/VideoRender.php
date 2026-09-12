<?php

namespace App\Models;

use App\Video\Render\Enums\RenderFailureClass;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * MỘT LƯỢT TIÊU TIỀN ĐÃ XẢY RA. Bất biến — không sửa, chỉ thêm dòng mới.
 *
 * Khác `VideoShot`: shot là TRẠNG THÁI HIỆN TẠI của kế hoạch (đổi được, ghi đè
 * được), render là SỰ KIỆN LỊCH SỬ. Trộn hai thứ đó đã sinh ra lỗi thật ngày
 * 2026-08-07: soạn lại session ghi đè `compiled_prompt` trong khi `artifact_path`
 * giữ nguyên, nên shot khoe một prompt không hề sinh ra tấm ảnh của nó.
 *
 * `sent_prompt` là NGUYÊN VĂN chuỗi đã rời khỏi máy, không phải chuỗi trong kế
 * hoạch. Hai thứ được phép khác nhau — compiler cắt bớt, ghép thêm, ẩn danh tên
 * riêng — và cái quyết định bức ảnh là cái cuối cùng.
 */
class VideoRender extends Model
{
    use HasUuids;

    protected $fillable = [
        'shot_id', 'design_image_id', 'video_session_id', 'asset_id',
        'attempt_no', 'idempotency_key', 'render_kind', 'provider', 'model',
        'sent_prompt', 'prompt_sha256', 'request_sha256', 'negative_prompt',
        'source_render_id', 'source_kind', 'requires_state', 'proves_state',
        'artifact_path', 'artifact_dir', 'width', 'height', 'duration_ms', 'bytes',
        'cost_usd', 'provider_ms', 'status', 'error_message',
        'proof_method', 'proof_verified',
        'provider_request_id', 'request_json', 'response_json',
        'request_hash', 'render_request_json', 'execution_status', 'claim_token',
        'claim_generation', 'claimed_by', 'claimed_at', 'lease_expires_at', 'heartbeat_at',
        'attempt_count', 'max_attempts', 'next_retry_at',
        'canonical_concept_revision_id', 'canonical_hash', 'projection_hash',
        'constraint_set_hash', 'prompt_spec_hash', 'provider_prompt_plan_hash',
        'prompt_hash', 'artifact_manifest', 'primary_artifact_hash',
        'failure_class', 'failure_code', 'failure_message', 'execution_version',
        'execution_started_at', 'execution_completed_at',
        'qa_status', 'latest_qa_run_id', 'qa_approved',
        'provider_job_id', 'provider_poll_count', 'provider_last_polled_at',
        'provider_next_poll_at', 'provider_submit_response_json',
        'provider_last_poll_response_json',
    ];

    protected $casts = [
        'attempt_no' => 'integer',
        'attempt_count' => 'integer',
        'max_attempts' => 'integer',
        'execution_version' => 'integer',
        'claim_generation' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration_ms' => 'integer',
        'bytes' => 'integer',
        'cost_usd' => 'float',
        'provider_ms' => 'integer',
        'proof_verified' => 'boolean',
        'qa_approved' => 'boolean',
        'provider_poll_count' => 'integer',
        'request_json' => 'array',
        'response_json' => 'array',
        'provider_submit_response_json' => 'array',
        'provider_last_poll_response_json' => 'array',
        'execution_status' => RenderStatus::class,
        'failure_class' => RenderFailureClass::class,
        'artifact_manifest' => 'array',
        'claimed_at' => 'immutable_datetime',
        'lease_expires_at' => 'immutable_datetime',
        'heartbeat_at' => 'immutable_datetime',
        'next_retry_at' => 'immutable_datetime',
        'execution_started_at' => 'immutable_datetime',
        'execution_completed_at' => 'immutable_datetime',
        'provider_last_polled_at' => 'immutable_datetime',
        'provider_next_poll_at' => 'immutable_datetime',
    ];

    public function shot()
    {
        return $this->belongsTo(VideoShot::class, 'shot_id');
    }

    /** Chủ sở hữu thứ hai của một lượt render — ô thiết kế ảnh, không thuộc session nào. */
    public function designImage()
    {
        return $this->belongsTo(VideoDesignImage::class, 'design_image_id');
    }

    public function session()
    {
        return $this->belongsTo(VideoSession::class, 'video_session_id');
    }

    public function attempts()
    {
        return $this->hasMany(VideoRenderAttempt::class, 'render_id');
    }

    public function qaRuns()
    {
        return $this->hasMany(RenderQaRun::class, 'render_id');
    }

    public function latestQaRun()
    {
        return $this->belongsTo(RenderQaRun::class, 'latest_qa_run_id');
    }

    public function repairs()
    {
        return $this->hasMany(RenderRepair::class, 'parent_render_id');
    }

    /** Tấm ảnh đã đẻ ra lượt render này — null nếu sinh từ chữ. */
    public function sourceRender()
    {
        return $this->belongsTo(self::class, 'source_render_id');
    }

    /** Những lượt render đã dùng lượt này làm ảnh nguồn. */
    public function derivedRenders()
    {
        return $this->hasMany(self::class, 'source_render_id');
    }

    public function isTerminal(): bool
    {
        return $this->execution_status instanceof RenderStatus
            && $this->execution_status->isTerminal();
    }
}
