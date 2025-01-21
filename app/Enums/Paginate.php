<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum Paginate: int
{

    use EnumTrait;
    case PAGE = 10; // Số lượng mục trên mỗi trang
}
