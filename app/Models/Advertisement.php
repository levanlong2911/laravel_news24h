<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Advertisement extends Model
{
    use HasFactory;
    use HasUuid;

    protected $keyType = 'string'; // UUID là chuỗi
    public $incrementing = false;

    protected $fillable = [
        'name',
        'position',
        'script',
        'active',
    ];

    public static function positions()
    {
        return ['top', 'middle', 'bottom', 'header', 'in-post'];
    }
}
