<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use HasFactory;
    protected $fillable = [
        'id', 'title', 'content', 'slug', 'category_id', 'author_id', 'thumbnail',
    ];

    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = true; // Giữ cho Eloquent tự động cập nhật created_at và updated_at
    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'post_tags', 'post_id', 'tag_id');
    }

    // Thiết lập quan hệ với Category
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
    // Thiết lập quan hệ với Category
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'author_id');
    }
}
