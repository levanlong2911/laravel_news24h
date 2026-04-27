<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Keyword extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'name',
        'short_name',
        'search_keyword',
        'extra_queries',
        'is_base',
        'is_active',
        'sort_order',
        'category_id',
    ];

    protected $casts = [
        'is_base'        => 'boolean',
        'is_active'      => 'boolean',
        'extra_queries'  => 'array',
    ];

    // Relationship
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    // Scopes
    public function scopeActive($q)  { return $q->where('is_active', true); }
    public function scopeBase($q)    { return $q->where('is_base', true); }
    public function scopeOrdered($q) { return $q->orderBy('sort_order')->orderBy('name'); }
}
