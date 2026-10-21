<?php

namespace App\Models\PromptLibrary;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pham vi ap dung: global, workflow, category, channel, project. Scope category
 * tro scope_key vao categories.slug; khong tu dung cay chu de rieng.
 */
class PromptScope extends Model
{
    use HasUuids;

    protected $table = 'ai_prompt_scopes';

    protected $fillable = ['scope_type', 'scope_key', 'name', 'metadata'];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function bindings(): HasMany
    {
        return $this->hasMany(PromptScopeBinding::class, 'scope_id');
    }
}
