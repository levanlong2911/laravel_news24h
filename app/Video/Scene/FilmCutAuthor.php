<?php

declare(strict_types=1);

namespace App\Video\Scene;

final class FilmCutAuthor
{
    public const CONTRACT_VERSION = 'film-cut-contract-v2';

    public const LEGACY_CONTRACT_VERSION = 'film-cut-contract-v1';

    public const SHAPE = 'film-cut-2';

    public const LEGACY_SHAPE = 'film-cut-1';

    public const PLANNER = 'screenplay';

    public static function isFilmCut(mixed $contract): bool
    {
        return in_array($contract, [self::CONTRACT_VERSION, self::LEGACY_CONTRACT_VERSION], true);
    }

    public static function isFilmShape(mixed $shape): bool
    {
        return in_array($shape, [self::SHAPE, self::LEGACY_SHAPE], true);
    }
}
