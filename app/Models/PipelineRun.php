<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id', 'stage', 'stage_version', 'contract_version', 'workflow_version',
        'input_hash', 'output_hash', 'input_json', 'output_json',
        'decision_trace', 'validation_errors',
        'duration_ms', 'cost_usd', 'token_input', 'token_output',
        'status', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'input_json'       => 'array',
        'output_json'      => 'array',
        'decision_trace'   => 'array',
        'validation_errors' => 'array',
        'started_at'       => 'datetime',
        'finished_at'      => 'datetime',
        'cost_usd'         => 'float',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'project_id');
    }

    public function scopeForStage($query, string $stage)
    {
        return $query->where('stage', $stage);
    }

    public function scopeCached($query, string $inputHash)
    {
        return $query->where('input_hash', $inputHash)->where('status', 'completed');
    }
}
