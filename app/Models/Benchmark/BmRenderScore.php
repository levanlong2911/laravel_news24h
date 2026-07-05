<?php

namespace App\Models\Benchmark;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BmRenderScore extends Model
{
    protected $table = 'bm_render_scores';

    protected $fillable = [
        'render_id',
        'identity_consistency', 'appearance_consistency',
        'geometry_consistency', 'temporal_consistency',
        'camera_obey', 'camera_continuity',
        'reveal_quality', 'motion_realism', 'physics',
        'emotion', 'cinematic_feel', 'eye_guidance',
        'overall', 'scored_by',
    ];

    public function render(): BelongsTo
    {
        return $this->belongsTo(BmRender::class, 'render_id');
    }

    /** Subject consistency sub-score = mean of the four sub-metrics. */
    public function subjectConsistency(): ?float
    {
        $values = array_filter([
            $this->identity_consistency,
            $this->appearance_consistency,
            $this->geometry_consistency,
            $this->temporal_consistency,
        ], fn($v) => $v !== null);

        return count($values) ? round(array_sum($values) / count($values), 2) : null;
    }
}
