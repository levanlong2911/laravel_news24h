<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaJob extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id', 'job_type', 'priority', 'payload', 'status',
        'attempt', 'worker_id',
        'planner_version', 'compiler_version', 'workflow_version', 'contract_version',
        'planning_ms', 'render_ms', 'cost_usd', 'token_input', 'token_output',
        'claimed_at', 'completed_at', 'error_message', 'outputs',
    ];

    protected $casts = [
        'payload'      => 'array',
        'outputs'      => 'array',
        'claimed_at'   => 'datetime',
        'completed_at' => 'datetime',
        'cost_usd'     => 'float',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'project_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending')->orderBy('priority', 'desc')->orderBy('created_at');
    }

    public function scopeClaimable($query)
    {
        return $query->where('status', 'pending')->where('attempt', '<', 3);
    }
}
