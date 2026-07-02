<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoAsset extends Model
{
    use HasUuids;

    protected $fillable = [
        'shot_id', 'asset_type', 'provider', 'remote_url', 'local_path', 'status', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function shot(): BelongsTo
    {
        return $this->belongsTo(VideoShot::class, 'shot_id');
    }
}
