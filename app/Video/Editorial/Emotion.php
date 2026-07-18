<?php

namespace App\Video\Editorial;

/** Cảm xúc chủ đạo của scene. Editorial taste. Khớp `aesthetic.emotion` (§6, §13). */
enum Emotion: string
{
    case Majestic   = 'MAJESTIC';
    case Tense      = 'TENSE';
    case Calm       = 'CALM';
    case Dramatic   = 'DRAMATIC';
    case Triumphant = 'TRIUMPHANT';
    case Sombre     = 'SOMBRE';
}
