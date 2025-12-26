<?php

namespace App\Models;

use App\Models\Scopes\DomainScope;
use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'id', 'title', 'content', 'slug', 'category_id', 'author_id', 'domain_id', 'thumbnail',
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

    public function author()
    {
        return $this->belongsTo(Admin::class, 'author_id', 'id');
    }

    public function domain()
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }

    protected static function booted()
    {
        static::addGlobalScope(new DomainScope);
    }

    public function scopeVisibleTo($query, $user)
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where('domain_id', $user->domain_id)
                    ->where('author_id', $user->id);
    }

}
