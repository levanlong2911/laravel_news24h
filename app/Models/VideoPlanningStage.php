<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VideoPlanningStage extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id', 'session_id', 'planning_revision', 'stage', 'status',
        'input_json', 'input_hash', 'raw_response', 'output_json', 'output_hash',
        'claim_token', 'claimed_at', 'lease_expires_at',
        'model', 'provider_model', 'instruction_version', 'tokens_in', 'tokens_out', 'cost_usd',
        'error_message', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'input_json' => 'array',
        'output_json' => 'array',
        'claimed_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'cost_usd' => 'float',
    ];

    public function project()
    {
        return $this->belongsTo(VideoProject::class, 'project_id');
    }

    public function session()
    {
        return $this->belongsTo(VideoSession::class, 'session_id');
    }
}
