<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Advertisement extends Model
{
    use HasFactory;

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
