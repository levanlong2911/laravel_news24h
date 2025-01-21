<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum Role: string
{
    use EnumTrait;

    case ADMIN = "admin";
    case MEMBER = "member";
}
