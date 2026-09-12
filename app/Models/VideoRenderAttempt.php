<?php

declare(strict_types=1);

namespace App\Models;

use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderFailureClass;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class VideoRenderAttempt extends Model
{
    use HasUuids;

    protected $table = 'render_attempts';

    protected $guarded = [];

    protected $casts = [
        'attempt_no' => 'integer',
        'claim_generation' => 'integer',
        'status' => RenderAttemptStatus::class,
        'failure_class' => RenderFailureClass::class,
        'artifact_manifest' => 'array',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'image_input_tokens' => 'integer',
        'text_input_tokens' => 'integer',
        'provider_cost_usd' => 'decimal:8',
        'provider_submit_response_json' => 'array',
        'http_status' => 'integer',
        'latency_ms' => 'integer',
        'started_at' => 'immutable_datetime',
        'submitted_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    public function render()
    {
        return $this->belongsTo(VideoRender::class, 'render_id');
    }
}
