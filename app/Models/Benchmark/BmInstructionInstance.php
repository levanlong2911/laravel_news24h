<?php

namespace App\Models\Benchmark;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BmInstructionInstance extends Model
{
    public $timestamps = false;

    protected $table = 'bm_instruction_instances';

    protected $fillable = [
        'render_id', 'catalog_id', 'beat',
        'variant_text', 'char_length', 'estimated_token_cost',
        'observed', 'confidence', 'annotated_by', 'annotated_at',
    ];

    protected $casts = ['annotated_at' => 'datetime'];

    public function render(): BelongsTo
    {
        return $this->belongsTo(BmRender::class, 'render_id');
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(BmInstruction::class, 'catalog_id');
    }

    public static function makeTokenCost(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
