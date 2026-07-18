<?php

namespace App\Video\Editorial;

/** Độ mạnh ánh sáng. Editorial taste (KHÁC world.light_source — cái đó là fact). */
enum LightIntensity: string
{
    case Soft    = 'SOFT';
    case Neutral = 'NEUTRAL';
    case Harsh   = 'HARSH';
}
