<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One revision of a package: the content for one project, one channel, one format and one language. A change of its
 * content is a new revision; a revision a person approved is never rewritten.
 */
class VideoSocialPackage extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const REVOKED = 'revoked';

    protected $fillable = [
        'package_group_id', 'revision', 'project_id', 'channel_id', 'format', 'language', 'previous_revision_id',
        'final_id', 'source_json', 'source_hash', 'ai_stage_id', 'ai_content_json', 'content_json', 'document_json',
        'package_hash', 'artwork_artifact_id', 'status', 'created_by', 'approved_at', 'revoked_at',
    ];

    protected $casts = [
        'revision' => 'integer',
        'source_json' => 'array',
        'ai_content_json' => 'array',
        'content_json' => 'array',
        'document_json' => 'array',
        'approved_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function channel()
    {
        return $this->belongsTo(VideoSocialChannel::class, 'channel_id');
    }

    public function publications()
    {
        return $this->hasMany(VideoSocialPublication::class, 'package_revision_id');
    }
}
