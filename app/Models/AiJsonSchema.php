<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class AiJsonSchema extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const PUBLISHED = 'published';

    protected $fillable = [
        'namespace', 'schema_key', 'version', 'name', 'schema_content', 'schema_sha256',
        'source_file', 'json_schema_id', 'json_schema_dialect', 'dependencies',
        'status', 'change_note', 'created_by', 'published_at',
    ];

    protected $casts = [
        'dependencies' => 'array',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (AiJsonSchema $schema): void {
            if ($schema->getOriginal('status') === self::PUBLISHED) {
                throw new RuntimeException("Schema {$schema->namespace}/{$schema->schema_key}@{$schema->version} is published and fixed; add a new version instead.");
            }
        });

        static::deleting(static function (AiJsonSchema $schema): void {
            if ($schema->status === self::PUBLISHED) {
                throw new RuntimeException("Schema {$schema->namespace}/{$schema->schema_key}@{$schema->version} is published and is never deleted.");
            }
        });
    }

    public function phaseLinks(): HasMany
    {
        return $this->hasMany(AiPhaseSchema::class, 'schema_id');
    }
}
