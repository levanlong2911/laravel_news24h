<?php

declare(strict_types=1);

namespace App\Models;

use App\Video\Render\Enums\QaDecision;
use App\Video\Render\Enums\QaStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class RenderQaRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'render_id',
        'qa_generation',
        'status',
        'decision',
        'repair_decision',
        'canonical_revision_id',
        'canonical_hash',
        'constraint_set_hash',
        'prompt_spec_hash',
        'request_hash',
        'artifact_id',
        'artifact_hash',
        'vision_provider',
        'vision_model',
        'qa_report_hash',
        'hard_fail_count',
        'soft_fail_count',
        'uncertain_count',
        'not_visible_count',
        'report_json',
        'completed_at',
    ];

    protected $casts = [
        'qa_generation' => 'integer',
        'status' => QaStatus::class,
        'decision' => QaDecision::class,
        'hard_fail_count' => 'integer',
        'soft_fail_count' => 'integer',
        'uncertain_count' => 'integer',
        'not_visible_count' => 'integer',
        'report_json' => 'array',
        'completed_at' => 'immutable_datetime',
    ];

    public function render()
    {
        return $this->belongsTo(VideoRender::class, 'render_id');
    }

    public function findings()
    {
        return $this->hasMany(RenderQaFinding::class, 'qa_run_id');
    }

    public function repairs()
    {
        return $this->hasMany(RenderRepair::class, 'qa_run_id');
    }
}
