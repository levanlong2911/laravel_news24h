<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class NewsSource extends Model
{
    use HasUuids;

    protected $fillable = ['domain', 'name', 'type', 'category', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public static function trustedDomains(): array
    {
        return Cache::remember('news_sources_trusted', 3600, fn() =>
            static::where('type', 'trusted')->where('is_active', true)->pluck('domain')->toArray()
        );
    }

    public static function blockedDomains(): array
    {
        return Cache::remember('news_sources_blocked', 3600, fn() =>
            static::where('type', 'blocked')->where('is_active', true)->pluck('domain')->toArray()
        );
    }

    public static function clearCache(): void
    {
        Cache::forget('news_sources_trusted');
        Cache::forget('news_sources_blocked');
    }
}
