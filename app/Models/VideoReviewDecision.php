<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class VideoReviewDecision extends Model
{
    use HasUuids;

    protected $table = 'video_review_decisions';

    protected $fillable = [
        'project_id', 'session_id', 'entity_type', 'entity_id', 'decision',
        'revision', 'reviewer_id', 'reason', 'metadata_json',
    ];

    protected $casts = [
        'revision' => 'integer',
        'metadata_json' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Review decisions are append-only.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Review decisions are append-only.');
        });
    }
}
