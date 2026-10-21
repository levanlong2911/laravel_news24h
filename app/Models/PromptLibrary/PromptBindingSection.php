<?php

namespace App\Models\PromptLibrary;

use App\Services\PromptLibrary\PromptLibraryException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptBindingSection extends Model
{
    use HasUuids;

    public const APPEND = 'append';

    public const PREPEND = 'prepend';

    public const REPLACE = 'replace';

    public const MERGE_MODES = [self::APPEND, self::PREPEND, self::REPLACE];

    protected $table = 'ai_prompt_binding_sections';

    protected $fillable = [
        'binding_id', 'document_id', 'section_key', 'slot_key', 'merge_mode', 'allow_override', 'required', 'sort_order',
    ];

    protected $casts = [
        'allow_override' => 'boolean',
        'required' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        $guard = function (self $row): void {
            $binding = PromptBinding::query()->find($row->binding_id);

            if ($binding === null || ! $binding->isEditable()) {
                throw new PromptLibraryException('Binding da publish: khong them, sua, xoa section duoc nua.');
            }

            if (! in_array($row->merge_mode, self::MERGE_MODES, true)) {
                throw new PromptLibraryException("merge_mode khong hop le: {$row->merge_mode}");
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    public function binding(): BelongsTo
    {
        return $this->belongsTo(PromptBinding::class, 'binding_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PromptDocument::class, 'document_id');
    }
}
