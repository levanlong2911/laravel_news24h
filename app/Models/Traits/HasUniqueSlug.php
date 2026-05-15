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

    /**
     * Create a record with automatic slug-collision retry.
     * Handles race conditions where two concurrent requests generate the same slug.
     * Non-slug unique violations (e.g. url_hash) are re-thrown immediately.
     */
    public static function retryCreate(array $attributes, string $slugBase, int $maxAttempts = 5): static
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $attributes['slug'] = static::uniqueSlug($slugBase);
            try {
                return static::create($attributes);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($i >= $maxAttempts - 1 || !str_contains(strtolower($e->getMessage()), 'slug')) {
                    throw $e;
                }
            }
        }
        throw new \RuntimeException('unreachable');
    }
}
