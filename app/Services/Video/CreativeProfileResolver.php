<?php

namespace App\Services\Video;

use App\Video\Inspiration\CategoryCreativeProfile;
use InvalidArgumentException;

final class CreativeProfileResolver
{
    public function resolve(string $category): ?CategoryCreativeProfile
    {
        $profileKey = config("video.creative_profiles.categories.{$category}");
        if (! is_string($profileKey) || $profileKey === '') {
            return null;
        }

        $profile = config("video.creative_profiles.profiles.{$profileKey}");
        if (! is_array($profile)) {
            throw new InvalidArgumentException("Creative profile {$profileKey} is not configured.");
        }

        return new CategoryCreativeProfile(
            $profileKey,
            (string) ($profile['mission'] ?? ''),
            $profile['article_patterns'] ?? [],
            $profile['inspection_aspects'] ?? [],
            $profile['excluded_context_types'] ?? [],
        );
    }
}
