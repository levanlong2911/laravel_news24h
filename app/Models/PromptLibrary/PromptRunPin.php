<?php

namespace App\Models\PromptLibrary;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Revision thuc te mot lan chay da dung. Chi PromptRunPinner ghi; mot execution
 * tai su dung (stage running het lease) thi pin cu duoc thay ca bo.
 */
class PromptRunPin extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'ai_prompt_run_pins';

    protected $fillable = [
        'execution_type', 'execution_id', 'binding_id', 'document_id', 'revision_id', 'alias', 'sort_order',
        'composition_hash', 'compiler_version', 'pinned_at',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'pinned_at' => 'datetime',
    ];
}
