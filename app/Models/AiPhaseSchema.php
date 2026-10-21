<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class AiPhaseSchema extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    public const ROLES = ['input', 'output', 'lock', 'review'];

    /** @var list<string> */
    public const MODES = ['whole', 'each_item'];

    protected $fillable = [
        'phase_id', 'schema_id', 'role', 'slot_key', 'data_pointer', 'validation_mode', 'is_required',
    ];

    protected $casts = [
        'is_required' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(static function (AiPhaseSchema $link): void {
            if (AiPhase::query()->whereKey($link->phase_id)->value('status') !== AiPhase::DRAFT) {
                throw new RuntimeException('Links are written only with a new draft revision.');
            }
        });

        static::updating(static function (): void {
            throw new RuntimeException('A link belongs to its revision and is never changed; save a new revision instead.');
        });

        static::deleting(static function (): void {
            throw new RuntimeException('A link belongs to its revision and is never deleted; save a new revision instead.');
        });
    }

    public static function isPointer(mixed $pointer): bool
    {
        return is_string($pointer)
            && strlen($pointer) <= 255
            && ($pointer === '' || str_starts_with($pointer, '/'))
            && preg_match('/~(?![01])/', $pointer) !== 1;
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(AiPhase::class, 'phase_id');
    }

    public function schema(): BelongsTo
    {
        return $this->belongsTo(AiJsonSchema::class, 'schema_id');
    }
}
