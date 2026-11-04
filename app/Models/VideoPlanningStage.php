<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VideoPlanningStage extends Model
{
    use HasUuids;

    /** @var list<string> every column but request_snapshot, for the lists and pages that never send a stage to a model */
    public const LISTING_COLUMNS = [
        'id', 'project_id', 'session_id', 'planning_revision', 'stage', 'status',
        'input_json', 'input_hash', 'raw_response', 'output_json', 'output_hash',
        'claim_token', 'claimed_at', 'lease_expires_at',
        'model', 'provider_model', 'instruction_version', 'tokens_in', 'tokens_out', 'thinking_tokens', 'cost_usd',
        'error_message', 'started_at', 'finished_at', 'created_at', 'updated_at',
    ];

    protected $fillable = [
        'project_id', 'session_id', 'planning_revision', 'stage', 'status',
        'input_json', 'input_hash', 'request_snapshot', 'raw_response', 'output_json', 'output_hash',
        'claim_token', 'claimed_at', 'lease_expires_at',
        'model', 'provider_model', 'instruction_version', 'tokens_in', 'tokens_out', 'thinking_tokens', 'cost_usd',
        'error_message', 'started_at', 'finished_at',
    ];

    protected $hidden = ['request_snapshot'];

    protected $casts = [
        'input_json' => 'array',
        'output_json' => 'array',
        'claimed_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'cost_usd' => 'float',
    ];

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeWithoutSnapshot($query)
    {
        return $query->select(array_map(fn (string $column): string => $this->qualifyColumn($column), self::LISTING_COLUMNS));
    }

    public function project()
    {
        return $this->belongsTo(VideoProject::class, 'project_id');
    }

    public function session()
    {
        return $this->belongsTo(VideoSession::class, 'session_id');
    }
}
