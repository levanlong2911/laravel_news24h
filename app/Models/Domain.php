<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{
    use HasFactory;
    use HasUuid;

    protected $table = 'domains';

    protected $fillable = [
        'id',
        'name',
        'host',
        'api_key',
        'is_active',
    ];

    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $attributes = [
        'api_key' => null,
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function admin()
    {
        return $this->belongsTo(Admin::class, 'domain_id');
    }

    // Domain có nhiều posts
    public function posts()
    {
        return $this->hasMany(Post::class, 'domain_id');
    }

    public function advertisements()
    {
        return $this->hasMany(Advertisement::class, 'domain_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }
}
