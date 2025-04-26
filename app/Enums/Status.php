<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum Status: int
{

    use EnumTrait;
    case active = 1;
    case noactive = 0;
}
