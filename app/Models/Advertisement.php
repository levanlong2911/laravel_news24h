<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Advertisement extends Model
{
    use HasFactory;
    use HasUuid;

    protected $keyType = 'string'; // UUID là chuỗi
    public $incrementing = false;

    protected $fillable = [
        'name',
        'position',
        'script',
        'domain_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function positions()
    {
        return ['top', 'middle', 'bottom', 'header', 'in-post'];
    }

    public function webSite()
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }

    /**
     * Scope: active ads
     */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /**
     * Scope: by domain or global
     */
    public function scopeForDomain(Builder $q, string $domainId): Builder
    {
        return $q->where(function ($sub) use ($domainId) {
            $sub->where('domain_id', $domainId)
                ->orWhereNull('domain_id');
        });
    }
}
