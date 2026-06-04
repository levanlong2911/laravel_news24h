<?php
namespace App\Support;

use Illuminate\Support\Facades\Cache;

final class CacheVersion
{
    public const ADS = 'v1';

    public static function posts(): string
    {
        return 'v' . Cache::get('cv:posts', 1);
    }

    public static function bumpPosts(): void
    {
        Cache::increment('cv:posts');
    }
}
