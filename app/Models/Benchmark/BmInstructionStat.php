<?php

namespace App\Models\Benchmark;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Materialized instruction statistics — incremented at annotation time.
 *
 * Never recomputed from scratch. Use incrementForInstance() when an
 * BmInstructionInstance is annotated (observed set from null to 0|1).
 *
 * benchmark:stats reads from this table. O(1) lookups regardless of DB size.
 */
class BmInstructionStat extends Model
{
    public $timestamps = false;

    protected $table = 'bm_instruction_stats';

    protected $fillable = [
        'catalog_id', 'scene_category', 'beat',
        'attempts', 'successes', 'total_char_length', 'total_token_cost',
    ];

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(BmInstruction::class, 'catalog_id');
    }

    /**
     * Update materialized stats when an instance is annotated.
     *
     * Handles two distinct cases to avoid double-counting:
     *
     * NULL → 0|1  (first annotation): increment attempts + adjust successes
     * 0|1  → 0|1  (re-annotation):    adjust successes only (attempt count unchanged)
     *
     * @param BmInstructionInstance $instance       Instance after observed is set
     * @param string                $sceneCategory  From render→fixture→scene_category
     * @param int|null              $previousObserved  null = first annotation
     */
    public static function updateForAnnotation(
        BmInstructionInstance $instance,
        string $sceneCategory,
        ?int $previousObserved,
    ): void {
        $newObserved = (int) $instance->observed;

        if ($previousObserved === null) {
            // First annotation — increment attempts and accumulate costs
            DB::table('bm_instruction_stats')->upsert(
                [[
                    'catalog_id'        => $instance->catalog_id,
                    'scene_category'    => $sceneCategory,
                    'beat'              => $instance->beat,
                    'attempts'          => 1,
                    'successes'         => $newObserved,
                    'total_char_length' => $instance->char_length,
                    'total_token_cost'  => $instance->estimated_token_cost,
                    'updated_at'        => now(),
                ]],
                ['catalog_id', 'scene_category', 'beat'],
                [
                    'attempts'          => DB::raw('attempts + 1'),
                    'successes'         => DB::raw("successes + {$newObserved}"),
                    'total_char_length' => DB::raw('total_char_length + ' . $instance->char_length),
                    'total_token_cost'  => DB::raw('total_token_cost + ' . $instance->estimated_token_cost),
                    'updated_at'        => now(),
                ]
            );
        } else {
            // Re-annotation — adjust success delta only; attempts and costs unchanged
            $delta = $newObserved - $previousObserved; // -1, 0, or +1
            if ($delta !== 0) {
                DB::table('bm_instruction_stats')
                    ->where('catalog_id', $instance->catalog_id)
                    ->where('scene_category', $sceneCategory)
                    ->where('beat', $instance->beat)
                    ->update([
                        'successes'  => DB::raw("successes + {$delta}"),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /** Success rate as percentage. */
    public function successRate(): float
    {
        return $this->attempts > 0
            ? round($this->successes / $this->attempts * 100, 1)
            : 0.0;
    }

    /** ROI = success_rate / avg_token_cost */
    public function roi(): float
    {
        $avgTokens = $this->attempts > 0 ? $this->total_token_cost / $this->attempts : 0;
        return $avgTokens > 0 ? round($this->successRate() / $avgTokens, 2) : 0.0;
    }
}
