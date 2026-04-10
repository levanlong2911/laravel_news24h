<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;

class RawArticle extends Model
{
    use HasUuids, MassPrunable;

    protected $fillable = [
        'keyword_id',
        'title',
        'url',
        'url_hash',
        'snippet',
        'source',
        'source_icon',
        'thumbnail',
        'viral_score',
        'position',
        'published_date',
        'stories_count',
        'top_story',
        'article_id',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'top_story'   => 'boolean',
        'viral_score' => 'integer',
        'position'    => 'integer',
        'stories_count' => 'integer',
        'expires_at'  => 'datetime',
    ];

    // Auto-xóa sau 24h
    public function prunable(): \Illuminate\Database\Eloquent\Builder
    {
        return static::where('expires_at', '<', now());
    }

    // ── Relationships ──────────────────────────────────────────────────────────
    public function keyword()
    {
        return $this->belongsTo(Keyword::class);
    }

    public function article()
    {
        return $this->belongsTo(Article::class);
    }

    // ── Computed: parse published_date string → relative time ──────────────────
    public function getPostedAgoAttribute(): string
    {
        $date = $this->published_date;
        if (empty($date)) return '—';

        // Thử parse trực tiếp (ISO 8601: "2026-04-09T03:00:00+00:00")
        $parsed = strtotime($date);

        // Fallback: format cũ "04/08/2026, 10:00 AM, +0000 UTC"
        if ($parsed === false) {
            $cleaned = trim(preg_replace('/,?\s*\+\d{4}\s*UTC$/i', '', $date));
            $parsed  = strtotime($cleaned);
        }

        if ($parsed === false) return $date;

        $diff = time() - $parsed;
        return match(true) {
            $diff < 60        => 'just now',
            $diff < 3600      => round($diff / 60) . 'm ago',
            $diff < 86400     => round($diff / 3600) . 'h ago',
            $diff < 86400 * 7 => round($diff / 86400) . 'd ago',
            default           => date('M j', $parsed),
        };
    }

    // ── Scopes ─────────────────────────────────────────────────────────────────
    public function scopePending($q)   { return $q->where('status', 'pending'); }
    public function scopeDone($q)      { return $q->where('status', 'done'); }
    public function scopeFailed($q)    { return $q->where('status', 'failed'); }
}
