<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoScene extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id', 'scene_number', 'title', 'emotion', 'goal',
        'duration', 'objects', 'location', 'lighting', 'color_grade', 'status',
    ];

    protected $casts = [
        'objects'  => 'array',
        'duration' => 'float',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'project_id');
    }

    public function shots(): HasMany
    {
        return $this->hasMany(VideoShot::class, 'scene_id')->orderBy('shot_number');
    }
}
