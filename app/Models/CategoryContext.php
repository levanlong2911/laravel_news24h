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
        'category_id', 'framework_id', 'video_framework_id',
        'domain', 'audience', 'terminology',
        'tone_notes', 'hook_style', 'art_style', 'custom_type_triggers',
        'performance_score', 'sample_size', 'is_active',
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

    /** purpose=video counterpart to framework() -- see 2026_06_20_000001 migration. */
    public function videoFramework(): BelongsTo
    {
        return $this->belongsTo(PromptFramework::class, 'video_framework_id');
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

    /**
     * Same idea as forCategory(), but eager-loads videoFramework instead of
     * framework -- used by the video pipeline (Fact Extractor/Story Planner/
     * Script Generator), never by the existing article pipeline.
     */
    public static function forCategoryVideo(string $categoryId): ?self
    {
        return self::with('videoFramework')
            ->where('category_id', $categoryId)
            ->where('is_active', true)
            ->whereNotNull('video_framework_id')
            ->first();
    }

}
