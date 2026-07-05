<?php

namespace App\Models\Benchmark;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
// BmRenderPlanner imported via same namespace — no use needed

class BmRender extends Model
{
    protected $table = 'bm_renders';

    protected $fillable = [
        'uuid', 'session_id', 'fixture_id',
        'model', 'resolution', 'duration_seconds', 'fps', 'seed',
        'char_count', 'prompt_version', 'artifact_path', 'git_commit',
        'rendered_at', 'annotated_at',
    ];

    protected $casts = [
        'rendered_at'  => 'datetime',
        'annotated_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(BmSession::class, 'session_id');
    }

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(BmFixture::class, 'fixture_id');
    }

    public function score(): HasOne
    {
        return $this->hasOne(BmRenderScore::class, 'render_id');
    }

    public function plannerOutputs(): HasMany
    {
        return $this->hasMany(BmPlannerOutput::class, 'render_id');
    }

    public function instructionInstances(): HasMany
    {
        return $this->hasMany(BmInstructionInstance::class, 'render_id');
    }

    public function renderPlanners(): HasMany
    {
        return $this->hasMany(BmRenderPlanner::class, 'render_id');
    }

    /** Prompt Efficiency = overall_score / (char_count / 1000) */
    public function promptEfficiency(): ?float
    {
        $overall = $this->score?->overall;
        if ($overall === null || $this->char_count === 0) {
            return null;
        }
        return round($overall / ($this->char_count / 1000), 2);
    }

    /** Annotation progress: fraction of instances with observed != null */
    public function annotationProgress(): float
    {
        $total = $this->instructionInstances()->count();
        if ($total === 0) {
            return 1.0;
        }
        $done = $this->instructionInstances()->whereNotNull('observed')->count();
        return round($done / $total, 4);
    }
}
