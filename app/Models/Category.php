<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $keyType = 'string'; // UUID là chuỗi
    public $incrementing = false; // Tắt tự động tăng ID

    protected $fillable = ['name'];

    public function tags()
    {
        return $this->hasMany(Tag::class);
    }
}
