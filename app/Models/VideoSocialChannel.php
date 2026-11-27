<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** The profile of one Fanpage or one YouTube channel: where a package goes and how it speaks. */
class VideoSocialChannel extends Model
{
    use HasUuids;

    public const ACTIVE = 'active';

    public const ARCHIVED = 'archived';

    protected $fillable = [
        'platform', 'display_name', 'external_id', 'url', 'language', 'timezone', 'audience', 'brand_voice',
        'default_goal', 'default_cta', 'default_link', 'disclosure', 'connection_id', 'verified_at', 'status', 'created_by',
    ];

    protected $casts = [
        'disclosure' => 'array',
        'verified_at' => 'datetime',
    ];

    public function connection()
    {
        return $this->belongsTo(VideoSocialConnection::class, 'connection_id');
    }

    /** @return bool whether a connection listed this very page or channel by the id the platform knows it by */
    public function verified(): bool
    {
        return $this->verified_at !== null && $this->connection_id !== null && $this->external_id !== null;
    }
}
