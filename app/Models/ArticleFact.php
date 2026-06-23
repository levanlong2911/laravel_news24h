<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleFact extends Model
{
    use HasUuids;

    protected $fillable = [
        'article_id', 'facts_json', 'confidence', 'escalated_to_sonnet', 'entities_json',
    ];

    protected $casts = [
        'facts_json' => 'array',
        'entities_json' => 'array',
        'escalated_to_sonnet' => 'boolean',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
