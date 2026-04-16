<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromptFramework extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'group_description',
        'system_prompt', 'phase1_analyze', 'phase2_diagnose', 'phase3_generate',
        'version', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function contentTypes(): HasMany
    {
        return $this->hasMany(FrameworkContentType::class, 'framework_id')
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PromptVersion::class, 'framework_id')->latest();
    }

    public function categoryContexts(): HasMany
    {
        return $this->hasMany(CategoryContext::class, 'framework_id');
    }
}
