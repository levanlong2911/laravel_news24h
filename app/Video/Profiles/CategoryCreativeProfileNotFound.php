<?php

declare(strict_types=1);

namespace App\Video\Profiles;

use RuntimeException;

final class CategoryCreativeProfileNotFound extends RuntimeException
{
    public static function forKey(
        string $key
    ): self {
        return new self(
            "Category creative profile not found: {$key}"
        );
    }
}
