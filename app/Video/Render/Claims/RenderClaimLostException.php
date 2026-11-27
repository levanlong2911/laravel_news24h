<?php

declare(strict_types=1);

namespace App\Video\Render\Claims;

use RuntimeException;

/**
 * The caller no longer holds the claim of the render it writes: its token was replaced, another worker owns the render, or its lease ran out.
 * Nothing was written; the worker API answers it as a conflict, every other caller sees the RuntimeException it always saw.
 */
final class RenderClaimLostException extends RuntimeException
{
}
