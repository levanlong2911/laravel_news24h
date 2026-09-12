<?php

declare(strict_types=1);

namespace App\Video\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ReferencePackQaRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'reference_pack_id',
        'status',
        'decision',
        'request_hash',
        'report_hash',
        'request_json',
        'report_json',
        'hard_fail_count',
        'soft_fail_count',
        'uncertain_count',
        'completed_at',
    ];

    protected $casts = [
        'request_json' => 'array',
        'report_json' => 'array',
        'hard_fail_count' => 'integer',
        'soft_fail_count' => 'integer',
        'uncertain_count' => 'integer',
        'completed_at' => 'immutable_datetime',
    ];

    public function referencePack()
    {
        return $this->belongsTo(ReferencePack::class, 'reference_pack_id');
    }
}
