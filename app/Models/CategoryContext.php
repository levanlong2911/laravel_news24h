<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoryContext extends Model
{
    use HasUuids;

    protected $fillable = [
        'category_id', 'framework_id',
        'domain', 'audience', 'terminology',
        'tone_notes', 'hook_style', 'custom_type_triggers',
        'performance_score', 'is_active',
    ];

    protected $casts = [
        'terminology'          => 'array',
        'custom_type_triggers' => 'array',
        'is_active'            => 'boolean',
    ];

    public function framework(): BelongsTo
    {
        return $this->belongsTo(PromptFramework::class, 'framework_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function outputFields(): HasMany
    {
        return $this->hasMany(CategoryOutputField::class, 'category_id', 'category_id')
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(PromptMetric::class, 'context_id');
    }

    /** Load context theo category, fallback về null nếu chưa có */
    public static function forCategory(string $categoryId): ?self
    {
        return self::with(['framework.contentTypes'])
            ->where('category_id', $categoryId)
            ->where('is_active', true)
            ->first();
    }

    /** Cập nhật performance_score sau khi generate */
    public function updatePerformanceScore(): void
    {
        $avg = $this->metrics()->avg('viral_score');
        $this->update(['performance_score' => round($avg ?? 0, 2)]);
    }
}
