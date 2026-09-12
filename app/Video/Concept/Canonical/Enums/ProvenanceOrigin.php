<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Enums;

enum ProvenanceOrigin: string
{
    case INSPIRED = 'inspired';
    case INVENTED = 'invented';
}
