<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'feed_source_id',
        'category_id',
        'title',
        'url',
        'url_hash',
        'thumbnail',
        'published_at',
        'raw_content',
        'status',
        'error_message',
        'article_id',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function feedSource(): BelongsTo
    {
        return $this->belongsTo(FeedSource::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public static function urlHash(string $url): string
    {
        return md5($url);
    }

    public static function existsByUrl(string $url): bool
    {
        return static::where('url_hash', static::urlHash($url))->exists();
    }
}
