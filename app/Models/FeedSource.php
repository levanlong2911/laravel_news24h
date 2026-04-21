<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeedSource extends Model
{
    use HasUuids;

    protected $fillable = [
        'category_id',
        'name',
        'url',
        'rss_url',
        'fetch_type',
        'crawl_selector',
        'fetch_interval_minutes',
        'is_active',
        'last_fetched_at',
        'total_fetched',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'last_fetched_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function feedItems(): HasMany
    {
        return $this->hasMany(FeedItem::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDue($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('last_fetched_at')
              ->orWhereRaw('DATE_ADD(last_fetched_at, INTERVAL fetch_interval_minutes MINUTE) <= NOW()');
        });
    }

    public function isDue(): bool
    {
        if (!$this->last_fetched_at) return true;
        return $this->last_fetched_at->addMinutes($this->fetch_interval_minutes)->isPast();
    }
}
