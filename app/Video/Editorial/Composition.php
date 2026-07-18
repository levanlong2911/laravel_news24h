<?php

namespace App\Video\Editorial;

/** Bố cục khung hình. Editorial taste. Khớp `aesthetic.composition` (§6, §13). */
enum Composition: string
{
    case Centered     = 'CENTERED';
    case RuleOfThirds = 'RULE_OF_THIRDS';
    case Symmetrical  = 'SYMMETRICAL';
    case LeadingLines = 'LEADING_LINES';
}
