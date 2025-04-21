<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum Paginate: int
{

    use EnumTrait;
    case PAGE = 20; // Số lượng mục trên mỗi trang
}
