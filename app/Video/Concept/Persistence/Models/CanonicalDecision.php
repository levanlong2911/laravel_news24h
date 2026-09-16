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
        // Thu tu extractor da sap. Thieu no o day thi `create()` LANG LE vut di va DB
        // ap default 0 cho moi hang — so cai mat thu tu ma khong mot loi nao duoc nem.
        'ordinal',
        // Cung ly do: van tay cua tung quyet dinh. Cot la char(64) NOT NULL DEFAULT '',
        // nen thieu o day thi moi hang mang van tay RONG ma khong ai bao.
        'value_hash',
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
