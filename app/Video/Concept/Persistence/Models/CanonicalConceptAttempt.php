<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Models;

use App\Video\Concept\Persistence\Enums\CanonicalAttemptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalAttemptType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CanonicalConceptAttempt extends Model
{
    use HasUuids;

    protected $table = 'canonical_concept_attempts';

    protected $fillable = [
        'canonical_concept_revision_id',
        'attempt_number',
        'attempt_type',
        'status',
        'provider',
        'model',
        'prompt_version',
        'input_hash',
        'schema_hash',
        'raw_output',
        'raw_output_hash',
        'provider_request_id',
        'stop_reason',
        'input_tokens',
        'output_tokens',
        'thinking_tokens',
        'cost_usd',
        'latency_ms',
        'error_code',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'attempt_type' => CanonicalAttemptType::class,
        'status' => CanonicalAttemptStatus::class,
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'thinking_tokens' => 'integer',
        'cost_usd' => 'decimal:6',
        'latency_ms' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CanonicalConceptRevision::class, 'canonical_concept_revision_id');
    }
}
