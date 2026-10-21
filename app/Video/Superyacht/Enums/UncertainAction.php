<?php

namespace App\Video\Superyacht\Enums;

enum UncertainAction: string
{
    case HUMAN_QUEUE = 'HUMAN_QUEUE';
    case TRY_NEXT_VARIANT = 'TRY_NEXT_VARIANT';
    case TRY_COVERAGE = 'TRY_COVERAGE';
    case ACCEPT_WITH_FLAG = 'ACCEPT_WITH_FLAG'; // flag P2_UNCERTAIN
}
