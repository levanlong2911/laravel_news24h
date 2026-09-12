<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Models;

use App\Models\VideoProject;
use App\Models\VideoSession;
use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

final class CanonicalConceptRevision extends Model
{
    use HasUuids;

    protected $table = 'canonical_concept_revisions';

    protected $fillable = [
        'video_project_id',
        'video_session_id',
        'revision',
        'status',
        'object_type',
        'profile_key',
        'profile_version',
        'canonical_schema_version',
        'effective_schema_hash',
        'concept_model',
        'concept_prompt_version',
        'semantic_validator_version',
        'normalizer_version',
        'canonicalizer_version',
        'canonical_json',
        'canonical_hash',
        'latest_raw_json',
        'latest_validation_errors',
        'repair_count',
        'concept_input_json',
        'concept_input_hash',
        'lock_version',
        'frozen_at',
        'failed_at',
        'failure_code',
        'failure_message',
    ];

    protected $casts = [
        'status' => CanonicalConceptStatus::class,
        'revision' => 'integer',
        'latest_validation_errors' => JsonValueCast::class,
        'repair_count' => 'integer',
        'lock_version' => 'integer',
        'frozen_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'video_project_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(VideoSession::class, 'video_session_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CanonicalConceptAttempt::class, 'canonical_concept_revision_id');
    }

    public function validationRuns(): HasMany
    {
        return $this->hasMany(CanonicalValidationRun::class, 'canonical_concept_revision_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(CanonicalDecision::class, 'canonical_concept_revision_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CanonicalConceptEvent::class, 'canonical_concept_revision_id');
    }

    public function isFrozen(): bool
    {
        return $this->status === CanonicalConceptStatus::FROZEN;
    }

    public function assertMutable(): void
    {
        if ($this->isFrozen()) {
            throw new LogicException(
                'Frozen canonical revision is immutable.'
            );
        }
    }

    /**
     * Revision truoc ma ban nay sinh ra tu do.
     *
     * So revision chi noi "lan thu may". Khi Vision QA bac Revision 3 va
     * Revision 4 ra doi, chi co lien ket nay noi duoc rang 4 la cau tra loi
     * cho 3 chu khong phai mot thiet ke roi rac cung du an.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'parent_revision_id'
        );
    }

    public function children(): HasMany
    {
        return $this->hasMany(
            self::class,
            'parent_revision_id'
        );
    }

    protected static function booted(): void
    {
        static::updating(function (CanonicalConceptRevision $model): void {
            $originalStatus = $model->getOriginal('status');

            if ($originalStatus === CanonicalConceptStatus::FROZEN->value) {
                $protected = [
                    'canonical_json',
                    'canonical_hash',
                    'revision',
                    'video_project_id',
                    'object_type',
                    'profile_key',
                    'profile_version',
                    'canonical_schema_version',
                    'effective_schema_hash',
                    'semantic_validator_version',
                    'normalizer_version',
                    'canonicalizer_version',
                    'concept_model',
                    'concept_prompt_version',
                    'frozen_at',
                    'parent_revision_id',
                ];

                foreach ($protected as $column) {
                    if ($model->isDirty($column)) {
                        throw new LogicException(
                            sprintf(
                                'Frozen canonical field "%s" is immutable.',
                                $column
                            )
                        );
                    }
                }

                if ($model->isDirty('status')) {
                    throw new LogicException(
                        'Frozen canonical revision cannot leave frozen state.'
                    );
                }
            }
        });

        static::deleting(function (CanonicalConceptRevision $model): void {
            if ($model->isFrozen()) {
                throw new LogicException(
                    'Frozen canonical revision cannot be deleted through the application model.'
                );
            }
        });
    }
}
