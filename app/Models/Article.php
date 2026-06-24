<?php

namespace App\Models;

use App\Models\Traits\HasUniqueSlug;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;

/** @method static string uniqueSlug(string $base, ?string $excludeId = null) */
class Article extends Model
{
    use HasUuids, MassPrunable, HasUniqueSlug;

    protected $fillable = [
        'keyword_id',
        'category_id',
        'source_url',
        'source_url_hash',
        'source_title',
        'source_name',
        'thumbnail',
        'title',
        'slug',
        'meta_description',
        'content',
        'content_hash',
        'content_simhash',
        'summary',
        'faq',
        'viral_score',
        'status',
        'human_review',
        'hook_type',
        'hook_score',
        'hook_rank',
        'content_blocks',
        'expires_at',
        'published_at',
        'crawled_by',
        'source_urls',
        'post_id',
        'video_skipped_at',
        'video_skip_reason',
        'video_failure_count',
        'video_processing_started_at',
    ];

    protected $casts = [
        'expires_at'       => 'datetime',
        'published_at'     => 'datetime',
        'video_skipped_at' => 'datetime',
        'video_processing_started_at' => 'datetime',
        'viral_score'  => 'integer',
        'faq'          => 'array',
        'human_review' => 'boolean',
        'hook_score'     => 'integer',
        'hook_rank'      => 'integer',
        'content_blocks' => 'array',
        'source_urls'    => 'array',
    ];

    // ── Auto-delete bài hết hạn sau 48h (chạy bởi: php artisan model:prune) ──
    public function prunable(): \Illuminate\Database\Eloquent\Builder
    {
        return static::where('expires_at', '<', now());
    }

    // ── Relationships ──────────────────────────────────────────────────────────
    public function keyword()
    {
        return $this->belongsTo(Keyword::class);
    }

    public function post()
    {
        return $this->belongsTo(\App\Models\Post::class);
    }

    public function rawArticle()
    {
        return $this->hasOne(RawArticle::class);
    }

    public function crawler()
    {
        return $this->belongsTo(\App\Models\Admin::class, 'crawled_by');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function articleFact()
    {
        return $this->hasOne(ArticleFact::class);
    }

    public function storyPlan()
    {
        return $this->hasOne(StoryPlan::class);
    }

    // ── Scopes ─────────────────────────────────────────────────────────────────
    public function scopePublished($q)
    {
        return $q->where('status', 'published');
    }

    public function scopePending($q)
    {
        return $q->where('status', 'pending');
    }

    public function scopeFailed($q)
    {
        return $q->where('status', 'failed');
    }

    public function scopeForKeyword($q, string $keywordId)
    {
        return $q->where('keyword_id', $keywordId);
    }
}
