<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class RssItem extends Model
{
    use HasUuids, MassPrunable;

    protected $fillable = [
        'news_web_id', 'article_id', 'title', 'url', 'url_hash',
        'image', 'description', 'published_at', 'status', 'expires_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'expires_at'   => 'datetime',
    ];

    public function prunable()
    {
        return static::where('expires_at', '<', now());
    }

    public function newsWeb()
    {
        return $this->belongsTo(NewsWeb::class);
    }

    public function article()
    {
        return $this->belongsTo(Article::class);
    }
}
