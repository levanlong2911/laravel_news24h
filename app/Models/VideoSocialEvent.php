<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** What happened to a package or a publication, in order. Rows are only ever added. */
class VideoSocialEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['project_id', 'package_revision_id', 'publication_id', 'type', 'actor_id', 'payload_json'];

    protected $casts = ['payload_json' => 'array'];

    /**
     * @param  array<string, mixed>  $payload  what happened, in the terms of the event; never a token or a session of a platform
     */
    public static function record(string $projectId, string $type, ?string $packageRevisionId = null, ?string $publicationId = null, ?string $actorId = null, array $payload = []): self
    {
        return self::create([
            'project_id' => $projectId,
            'package_revision_id' => $packageRevisionId,
            'publication_id' => $publicationId,
            'type' => $type,
            'actor_id' => $actorId,
            'payload_json' => $payload,
        ]);
    }
}
