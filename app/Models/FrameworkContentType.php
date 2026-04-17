<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FrameworkContentType extends Model
{
    use HasUuids;

    protected $fillable = [
        'framework_id', 'type_code', 'type_name',
        'trigger_keywords', 'tone_profile', 'structure_template',
        'sort_order', 'is_active', 'applicability_score',
    ];

    protected $casts = [
        'trigger_keywords'    => 'array',
        'tone_profile'        => 'array',
        'is_active'           => 'boolean',
        'applicability_score' => 'float',
    ];

    public function framework(): BelongsTo
    {
        return $this->belongsTo(PromptFramework::class, 'framework_id');
    }
}
