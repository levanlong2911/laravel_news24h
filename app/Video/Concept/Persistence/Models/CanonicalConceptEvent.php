<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Models;

use App\Video\Concept\Persistence\Enums\CanonicalEventType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CanonicalConceptEvent extends Model
{
    use HasUuids;

    protected $table = 'canonical_concept_events';

    protected $fillable = [
        'canonical_concept_revision_id',
        'event_type',
        'from_status',
        'to_status',
        'metadata',
        'event_key',
        'occurred_at',
    ];

    protected $casts = [
        'event_type' => CanonicalEventType::class,
        'metadata' => JsonValueCast::class,
        'occurred_at' => 'datetime',
    ];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CanonicalConceptRevision::class, 'canonical_concept_revision_id');
    }
}
