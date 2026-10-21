<?php

namespace App\Video\Superyacht\Enums;

enum LockType: string
{
    case DESIGN = 'design';
    case TIMING = 'timing';
    case SPEC = 'spec';
    case PACING = 'pacing';
    case PUBLISH = 'publish';
}
