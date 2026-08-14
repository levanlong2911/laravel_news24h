<?php

namespace App\Video\Concept;

enum Viewpoint: string
{
    case FrontThreeQuarter = 'front_three_quarter';
    case Side = 'side';
    case RearThreeQuarter = 'rear_three_quarter';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
