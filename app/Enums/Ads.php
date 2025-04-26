<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum Ads: string
{
    use EnumTrait;

    case Top = "top";
    case Middle = "middle";
    case Bottom = "bottom";
}
