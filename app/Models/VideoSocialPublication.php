<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One posting of one approved package revision. A posting a person reports after posting by hand and a posting sent
 * through the API are told apart, and so is what each step of an API posting came to.
 */
class VideoSocialPublication extends Model
{
    use HasUuids;

    public const MANUAL = 'manual';

    public const API = 'api';

    public const MANUAL_REPORTED = 'manual_reported';

    public const QUEUED = 'queued';

    public const UPLOADING = 'uploading';

    public const PROCESSING = 'processing';

    public const SCHEDULED = 'scheduled';

    public const PUBLISHED = 'published';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    public const CANCELLED = 'cancelled';

    /** @var list<string> the statuses of an API posting that may still reach the platform, or already reached it without a final answer */
    public const OPEN = [self::QUEUED, self::UPLOADING, self::PROCESSING, self::SCHEDULED, self::UNKNOWN];

    protected $fillable = [
        'package_revision_id', 'package_hash', 'project_id', 'channel_id', 'method', 'status', 'request_key', 'intent',
        'external_id', 'external_url', 'scheduled_at', 'published_at', 'requested_by', 'confirmed_by',
        'payload_json', 'payload_hash', 'adapter_version', 'steps_json', 'session', 'error_code', 'error_message',
        'claim_token', 'claimed_at',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'steps_json' => 'array',
        'session' => 'encrypted:array',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    protected $hidden = ['session'];

    public function package()
    {
        return $this->belongsTo(VideoSocialPackage::class, 'package_revision_id');
    }

    public function channel()
    {
        return $this->belongsTo(VideoSocialChannel::class, 'channel_id');
    }

    public function metrics()
    {
        return $this->hasMany(VideoSocialMetric::class, 'publication_id');
    }
}
