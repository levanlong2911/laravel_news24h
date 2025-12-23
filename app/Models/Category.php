<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;
    use HasUuid;

    protected $keyType = 'string'; // UUID là chuỗi
    public $incrementing = false; // Tắt tự động tăng ID

    protected $fillable = ['name'];

    public function tags()
    {
        return $this->hasMany(Tag::class);
    }

    // Thiết lập quan hệ ngược lại với Post
    public function posts()
    {
        return $this->hasMany(Post::class, 'category_id');
    }
}
