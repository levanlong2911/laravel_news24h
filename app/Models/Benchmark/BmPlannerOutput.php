<?php

namespace App\Models\Benchmark;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BmPlannerOutput extends Model
{
    public $timestamps = false;

    protected $table = 'bm_planner_outputs';

    protected $fillable = ['render_id', 'planner_id', 'beat', 'raw_text'];

    protected $casts = ['created_at' => 'datetime'];

    public function render(): BelongsTo
    {
        return $this->belongsTo(BmRender::class, 'render_id');
    }

    public function planner(): BelongsTo
    {
        return $this->belongsTo(BmPlanner::class, 'planner_id');
    }
}
