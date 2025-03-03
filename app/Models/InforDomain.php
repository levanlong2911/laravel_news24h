<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InforDomain extends Model
{
    use HasFactory;

    protected $keyType = 'string'; // UUID là chuỗi
    public $incrementing = false; // Tắt tự động tăng ID

    // Các cột có thể được gán giá trị
    protected $fillable = ['domain', 'key_class'];
}
