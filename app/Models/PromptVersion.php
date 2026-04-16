<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptVersion extends Model
{
    use HasUuids;

    protected $fillable = [
        'framework_id', 'snapshot', 'changed_by', 'change_note',
    ];

    protected $casts = [
        'snapshot' => 'array',
    ];

    public function framework(): BelongsTo
    {
        return $this->belongsTo(PromptFramework::class, 'framework_id');
    }
}
