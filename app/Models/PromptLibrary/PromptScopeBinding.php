<?php

namespace App\Models\PromptLibrary;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptScopeBinding extends Model
{
    use HasUuids;

    protected $table = 'ai_prompt_scope_bindings';

    protected $fillable = ['scope_id', 'binding_id', 'priority', 'is_active'];

    protected $casts = [
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scope(): BelongsTo
    {
        return $this->belongsTo(PromptScope::class, 'scope_id');
    }

    public function binding(): BelongsTo
    {
        return $this->belongsTo(PromptBinding::class, 'binding_id');
    }
}
