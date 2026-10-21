<?php

namespace App\Models\PromptLibrary;

use App\Services\PromptLibrary\PromptLibraryException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromptDocument extends Model
{
    use HasUuids;

    protected $table = 'ai_prompt_documents';

    protected $fillable = ['namespace', 'key', 'name', 'document_type', 'description', 'active_revision_id', 'is_active', 'metadata'];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    /** "<namespace>.<key>", vi du "video.storyboard_v4". */
    public static function findByFullKey(string $fullKey): ?self
    {
        [$namespace, $key] = self::splitFullKey($fullKey);

        return self::query()->where('namespace', $namespace)->where('key', $key)->first();
    }

    /** @return array{0: string, 1: string} */
    public static function splitFullKey(string $fullKey): array
    {
        $dot = strpos($fullKey, '.');

        if ($dot === false || $dot === 0 || $dot === strlen($fullKey) - 1) {
            throw new PromptLibraryException("Document key phai co dang <namespace>.<key>: {$fullKey}");
        }

        return [substr($fullKey, 0, $dot), substr($fullKey, $dot + 1)];
    }

    public function fullKey(): string
    {
        return $this->namespace.'.'.$this->key;
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PromptRevision::class, 'document_id');
    }

    public function activeRevision(): BelongsTo
    {
        return $this->belongsTo(PromptRevision::class, 'active_revision_id');
    }
}
