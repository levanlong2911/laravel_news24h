<?php

namespace App\Services\AI\Provider\Circuit;

enum CircuitState: string
{
    case CLOSED    = 'closed';      // Normal operation — requests pass through.
    case OPEN      = 'open';        // Provider down — fail fast, don't call the API.
    case HALF_OPEN = 'half_open';   // Probing recovery — allow one request through.
}
