<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VideoCostEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id', 'session_id', 'entity_type', 'entity_id', 'stage',
        'provider', 'model', 'usage_type', 'quantity', 'unit', 'cost_usd',
        'metadata_json',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
        'cost_usd' => 'decimal:6',
        'metadata_json' => 'array',
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
