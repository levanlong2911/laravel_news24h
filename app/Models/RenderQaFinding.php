<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class RenderQaFinding extends Model
{
    use HasUuids;

    protected $fillable = [
        'qa_run_id',
        'target_id',
        'semantic_key',
        'primitive',
        'priority',
        'severity',
        'status',
        'confidence',
        'expected_value',
        'observed_value',
        'evidence',
        'failure_type',
        'source_constraint_ids',
        'source_instruction_ids',
        'source_paths',
    ];

    protected $casts = [
        'priority' => 'integer',
        'confidence' => 'decimal:4',
        'expected_value' => 'array',
        'observed_value' => 'array',
        'source_constraint_ids' => 'array',
        'source_instruction_ids' => 'array',
        'source_paths' => 'array',
    ];

    public function qaRun()
    {
        return $this->belongsTo(RenderQaRun::class, 'qa_run_id');
    }
}
