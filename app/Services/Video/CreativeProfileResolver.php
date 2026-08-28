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

        // Constructor tự từ chối cấu hình vô nghiệm — nổ ở đây, trước khi có cú
        // gọi model nào.
        return new CategoryCreativeProfile(
            $profileKey,
            (string) ($profile['mission'] ?? ''),
            $profile['article_patterns'] ?? [],
            $profile['inspection_aspects'] ?? [],
            $profile['excluded_context_types'] ?? [],
            $profile['identity_slots'] ?? [],
            (string) ($profile['concept_mission'] ?? ''),
            $profile['viewpoint_guidance'] ?? [],
            $profile['arc_stages'] ?? [],
            $profile['arc_required_stages'] ?? [],
            $profile['concept_antipatterns'] ?? [],
            $profile['concept_forbidden_terms'] ?? [],
            $profile['identity_cross_checks'] ?? [],
            $profile['design_spec_export'] ?? [],
        );
    }
}
