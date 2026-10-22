<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class AiPhase extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    protected $fillable = [
        'category_id', 'workflow_code', 'phase_code', 'revision', 'kind', 'name', 'sort_order',
        'prompt_content', 'content_sha256', 'needs', 'parallel_with', 'stop',
        'status', 'is_current', 'based_on_id', 'source_file', 'source_section_key',
        'change_note', 'created_by', 'published_at',
    ];

    protected $casts = [
        'revision' => 'integer',
        'sort_order' => 'integer',
        'is_current' => 'boolean',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (AiPhase $phase): void {
            $changed = array_keys($phase->getDirty());
            $was = $phase->getOriginal('status');
            $publishing = $was === self::DRAFT && $phase->status === self::PUBLISHED
                && array_diff($changed, ['status', 'is_current', 'published_at']) === [];
            $retiringCurrent = $was === self::PUBLISHED && $changed === ['is_current'] && ! $phase->is_current;

            if (! $publishing && ! $retiringCurrent) {
                throw new RuntimeException("Revision {$phase->revision} of {$phase->phase_code} is fixed; save a new revision instead.");
            }
        });

        static::deleting(static function (AiPhase $phase): void {
            throw new RuntimeException("Revision {$phase->revision} of {$phase->phase_code} is kept as history and is never deleted.");
        });
    }

    public static function currentFor(string $categoryId, string $workflowCode, string $phaseCode): self
    {
        $rows = self::query()
            ->where('category_id', $categoryId)
            ->where('workflow_code', $workflowCode)
            ->where('phase_code', $phaseCode)
            ->where('status', self::PUBLISHED)
            ->where('is_current', true)
            ->get();

        if ($rows->count() !== 1) {
            throw new RuntimeException("{$phaseCode} of {$workflowCode} has {$rows->count()} current published revisions for this category; expected exactly one.");
        }

        $phase = $rows->first();

        if (! hash_equals((string) $phase->content_sha256, hash('sha256', (string) $phase->prompt_content))) {
            throw new RuntimeException("Revision {$phase->revision} of {$phaseCode} does not match its stored hash.");
        }

        return $phase;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function schemaLinks(): HasMany
    {
        return $this->hasMany(AiPhaseSchema::class, 'phase_id');
    }
}
