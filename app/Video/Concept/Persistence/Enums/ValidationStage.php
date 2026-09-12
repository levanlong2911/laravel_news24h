<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Enums;

enum ValidationStage: string
{
    case CORE_SCHEMA = 'core_schema';
    case EFFECTIVE_SCHEMA = 'effective_schema';
    case DTO_HYDRATION = 'dto_hydration';
    case CROSS_FIELD = 'cross_field';
    case PROVENANCE = 'provenance';
    case PROFILE_COMPATIBILITY = 'profile_compatibility';
    case CATEGORY_SEMANTIC = 'category_semantic';
    case POST_NORMALIZATION_CORE = 'post_normalization_core';
    case POST_NORMALIZATION_EFFECTIVE = 'post_normalization_effective';
    case POST_NORMALIZATION_SEMANTIC = 'post_normalization_semantic';
    case COMPILABILITY = 'compilability';
}
