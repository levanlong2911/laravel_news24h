<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConvertFont extends Model
{
    use HasFactory;
    protected $keyType = 'string'; // UUID là chuỗi
    public $incrementing = false;

    protected $fillable = [
        'find',
        'replace',
    ];
}
