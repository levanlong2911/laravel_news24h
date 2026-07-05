<?php

namespace App\Models\Benchmark;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BmPlanner extends Model
{
    protected $table = 'bm_planner_registry';

    protected $fillable = ['name', 'file_path', 'fingerprint', 'version'];

    public function instructions(): HasMany
    {
        return $this->hasMany(BmInstruction::class, 'planner_id');
    }

    public function plannerOutputs(): HasMany
    {
        return $this->hasMany(BmPlannerOutput::class, 'planner_id');
    }

    /** Recompute SHA-256 fingerprint from current file on disk. */
    public function refreshFingerprint(): bool
    {
        $abs = app_path($this->file_path);
        if (! file_exists($abs)) {
            return false;
        }
        $this->fingerprint = hash_file('sha256', $abs);
        return $this->save();
    }

    /** Success rate across all instruction instances for this planner. */
    public function successRate(): ?float
    {
        $result = BmInstructionInstance::query()
            ->join('bm_instruction_catalog', 'bm_instruction_instances.catalog_id', '=', 'bm_instruction_catalog.id')
            ->where('bm_instruction_catalog.planner_id', $this->id)
            ->whereNotNull('bm_instruction_instances.observed')
            ->selectRaw('AVG(bm_instruction_instances.observed) as rate')
            ->value('rate');

        return $result !== null ? (float) $result * 100 : null;
    }
}
