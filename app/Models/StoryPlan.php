<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoryPlan extends Model
{
    use HasUuids;

    protected $fillable = [
        'article_id', 'hook', 'narrative_arc', 'mood', 'content_type', 'visual_anchor', 'total_parts', 'parts_outline_json',
    ];

    protected $casts = [
        'parts_outline_json' => 'array',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function videoJobs(): HasMany
    {
        return $this->hasMany(VideoJob::class)->orderBy('part_number');
    }
}
