<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class VideoSessionEvent extends Model
{
    use HasUuids;

    protected $table = 'video_session_events';

    protected $fillable = [
        'session_id', 'event_type', 'entity_type', 'entity_id', 'payload_json',
        'operation_id', 'operation_payload_hash', 'operation_result_json',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'operation_result_json' => 'array',
    ];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Session events are append-only.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Session events are append-only.');
        });
    }
}
