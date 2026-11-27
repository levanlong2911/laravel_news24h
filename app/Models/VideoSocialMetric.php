<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One number a platform gave for one publication over one span; `availability` says when it gave none, and the value is then null, never zero. */
class VideoSocialMetric extends Model
{
    use HasUuids;

    public const AVAILABLE = 'available';

    public const NOT_PROVIDED = 'not_provided';

    protected $fillable = ['publication_id', 'metric', 'value', 'availability', 'period_start', 'period_end', 'period_key', 'fetched_at', 'source', 'raw_json'];

    protected $casts = [
        'value' => 'float',
        'period_start' => 'date',
        'period_end' => 'date',
        'fetched_at' => 'datetime',
        'raw_json' => 'array',
    ];
}
