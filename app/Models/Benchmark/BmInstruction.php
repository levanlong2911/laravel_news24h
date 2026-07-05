<?php

namespace App\Models\Benchmark;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BmInstruction extends Model
{
    protected $table = 'bm_instruction_catalog';

    protected $fillable = [
        'code', 'planner_id', 'category',
        'description', 'introduced_in', 'deprecated_in',
    ];

    public function planner(): BelongsTo
    {
        return $this->belongsTo(BmPlanner::class, 'planner_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(BmInstructionInstance::class, 'catalog_id');
    }

    public function isActive(): bool
    {
        return $this->deprecated_in === null;
    }

    /**
     * Success rate across all annotated instances.
     * Optionally scoped by category / beat for fine-grained analytics.
     */
    public function successRate(?string $sceneCategory = null, ?string $beat = null): ?float
    {
        $query = $this->instances()->whereNotNull('observed');

        if ($sceneCategory !== null) {
            $query->whereHas('render.fixture', fn($q) => $q->where('scene_category', $sceneCategory));
        }
        if ($beat !== null) {
            $query->where('beat', $beat);
        }

        $rate = $query->avg('observed');
        return $rate !== null ? (float) $rate * 100 : null;
    }

    /** token ROI = success_rate / avg_token_cost */
    public function tokenRoi(): ?float
    {
        $avgCost = $this->instances()->avg('estimated_token_cost');
        if (! $avgCost) {
            return null;
        }
        $rate = $this->successRate();
        return $rate !== null ? round($rate / $avgCost, 2) : null;
    }
}
