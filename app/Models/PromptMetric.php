<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptMetric extends Model
{
    use HasUuids;

    protected $fillable = [
        'context_id', 'article_id', 'content_type_detected',
        'viral_score', 'word_count', 'processing_time_ms', 'model_used',
        'hook_score', 'hook_rank', 'hook_candidates',
        'guard_confidence', 'final_reason',
        'retry_count', 'retry_reason',
        'schema_version', 'prompt_fingerprint',
        'needs_review', 'cleaner_reduction_ratio', 'used_haiku',
        'haiku_input_tokens', 'haiku_output_tokens',
        'sonnet_input_tokens', 'sonnet_output_tokens',
        'total_cost_usd',
    ];

    public function context(): BelongsTo
    {
        return $this->belongsTo(CategoryContext::class, 'context_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }
}
