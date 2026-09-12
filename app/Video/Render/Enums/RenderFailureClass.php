<?php

declare(strict_types=1);

namespace App\Video\Render\Enums;

enum RenderFailureClass: string
{
    case TRANSIENT_NETWORK = 'transient_network';
    case RATE_LIMIT = 'rate_limit';
    case PROVIDER_5XX = 'provider_5xx';
    case AUTHENTICATION = 'authentication';
    case INVALID_REQUEST = 'invalid_request';
    case UNSUPPORTED_CAPABILITY = 'unsupported_capability';
    case ARTIFACT_INTEGRITY = 'artifact_integrity';
    case AMBIGUOUS_PROVIDER_OUTCOME = 'ambiguous_provider_outcome';
    case INTERNAL = 'internal';
}

