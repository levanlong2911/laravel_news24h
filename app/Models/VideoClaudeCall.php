<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoClaudeCall extends Model
{
    use HasUuids;

    protected $fillable = [
        'article_id', 'stage', 'model_used', 'input_tokens', 'output_tokens', 'cost_usd',
    ];

    protected $casts = [
        'cost_usd' => 'float',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
