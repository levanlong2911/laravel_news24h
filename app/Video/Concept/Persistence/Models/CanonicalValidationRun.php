<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Models;

use App\Video\Concept\Persistence\Enums\ValidationRunStatus;
use App\Video\Concept\Persistence\Enums\ValidationStage;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CanonicalValidationRun extends Model
{
    use HasUuids;

    protected $table = 'canonical_validation_runs';

    protected $fillable = [
        'canonical_concept_revision_id',
        'canonical_concept_attempt_id',
        'stage',
        'status',
        'errors',
        'error_count',
        'document_hash',
        'validator_version',
        'duration_ms',
        'validated_at',
    ];

    protected $casts = [
        'stage' => ValidationStage::class,
        'status' => ValidationRunStatus::class,
        'errors' => JsonValueCast::class,
        'error_count' => 'integer',
        'duration_ms' => 'integer',
        'validated_at' => 'datetime',
    ];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CanonicalConceptRevision::class, 'canonical_concept_revision_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(CanonicalConceptAttempt::class, 'canonical_concept_attempt_id');
    }
}
