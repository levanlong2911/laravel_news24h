<?php

namespace App\Video\Story;

/**
 * Act = một node hoặc edge của World Graph được chọn để kể.
 *
 * Phải khớp `act.source` trong contracts/renderplan/v1.0/schema.json.
 * Xem docs/video/ARCHITECTURE.md §3.
 */
enum ActSource: string
{
    case Entity   = 'ENTITY';
    case Event    = 'EVENT';
    case Relation = 'RELATION';
}
