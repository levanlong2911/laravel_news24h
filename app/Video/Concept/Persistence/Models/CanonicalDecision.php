<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Models;

use App\Video\Concept\Persistence\Enums\DecisionOrigin;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CanonicalDecision extends Model
{
    use HasUuids;

    protected $table = 'canonical_decisions';

    protected $fillable = [
        'canonical_concept_revision_id',
        'target_path',
        'decision_type',
        'value',
        'provenance_origin',
        'source_aspects',
        'invariant_ids',
        'relationship_ids',
        'origin',
    ];

    protected $casts = [
        'value' => JsonValueCast::class,
        'source_aspects' => JsonValueCast::class,
        'invariant_ids' => JsonValueCast::class,
        'relationship_ids' => JsonValueCast::class,
        'origin' => DecisionOrigin::class,
        'ordinal' => 'integer',
    ];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CanonicalConceptRevision::class, 'canonical_concept_revision_id');
    }
}
