<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use HasFactory;
    protected $fillable = [
        'title', 'content', 'slug', 'category_id', 'author_id', 'thumbnail', 'published_at', 'is_active'
    ];

    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = true; // Giữ cho Eloquent tự động cập nhật created_at và updated_at
    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id');
    }
}
