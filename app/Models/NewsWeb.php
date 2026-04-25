<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NewsWeb extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'category_id',
        'domain',
        'base_url',
        'is_active',
        'is_trusted',
        'is_blocked'
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
