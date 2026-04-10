<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;

class Article extends Model
{
    use HasUuids, MassPrunable;

    protected $fillable = [
        'keyword_id',
        'source_url',
        'source_url_hash',
        'source_title',
        'source_name',
        'thumbnail',
        'title',
        'slug',
        'meta_description',
        'content',
        'summary',
        'faq',
        'viral_score',
        'status',
        'expires_at',
        'published_at',
    ];

    protected $casts = [
        'expires_at'   => 'datetime',
        'published_at' => 'datetime',
        'viral_score'  => 'integer',
        'faq'          => 'array',
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

    public function rawArticle()
    {
        return $this->hasOne(RawArticle::class);
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
