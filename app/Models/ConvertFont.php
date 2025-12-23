<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConvertFont extends Model
{
    use HasFactory;
    use HasUuid;

    protected $keyType = 'string'; // UUID là chuỗi
    public $incrementing = false;

    protected $fillable = [
        'find',
        'replace',
    ];
}
