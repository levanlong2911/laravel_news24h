<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\ExecutionGraph;

/**
 * Loại dependency giữa các execution node.
 *
 * REQUIRES → hard dependency: nếu parent FAILED, child bị SKIP
 * SOFT     → soft dependency: child vẫn chạy dù parent FAILED
 */
enum ExecutionRelation: string
{
    case REQUIRES = 'requires';
    case SOFT     = 'soft';
}
