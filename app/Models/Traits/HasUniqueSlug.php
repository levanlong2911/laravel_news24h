<?php

namespace App\Models\Traits;

trait HasUniqueSlug
{
    public static function uniqueSlug(string $base, ?string $excludeId = null): string
    {
        $slug    = $base ?: 'article';
        $counter = 1;

        while (
            static::where('slug', $slug)
                ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
                ->exists()
        ) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
