<?php

namespace App\Models\PromptLibrary;

use App\Services\PromptLibrary\PromptLibraryException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cong thuc lap prompt cho mot consumer. Da publish thi bat bien (chi chuyen
 * duoc sang retired); muon doi cong thuc thi tao version moi.
 */
class PromptBinding extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const RETIRED = 'retired';

    protected $table = 'ai_prompt_bindings';

    protected $fillable = ['consumer_key', 'version', 'status', 'compiler_version', 'description', 'published_at'];

    protected $casts = [
        'version' => 'integer',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $binding): void {
            $from = (string) $binding->getOriginal('status');

            if ($from === self::DRAFT) {
                return;
            }

            $dirty = array_keys($binding->getDirty());
            $retiring = $from === self::PUBLISHED && $binding->status === self::RETIRED;

            if (! $retiring || array_diff($dirty, ['status', 'updated_at']) !== []) {
                throw new PromptLibraryException("Binding {$binding->consumer_key} v{$binding->version} da {$from}: tao version moi.");
            }
        });

        static::deleting(function (self $binding): void {
            if ($binding->getOriginal('status') !== self::DRAFT) {
                throw new PromptLibraryException("Binding {$binding->consumer_key} v{$binding->version} da ra khoi draft, khong xoa duoc.");
            }
        });
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function sections(): HasMany
    {
        return $this->hasMany(PromptBindingSection::class, 'binding_id')->orderBy('sort_order');
    }
}
