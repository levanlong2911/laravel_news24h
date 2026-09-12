<?php

declare(strict_types=1);

namespace App\Video\Identity\Models;

use App\Models\VideoProject;
use App\Video\Identity\Enums\IdentityLockStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class IdentityLock extends Model
{
    use HasUuids;

    protected $fillable = [
        'video_project_id',
        'canonical_concept_revision_id',
        'canonical_hash',
        'approved_anchor_id',
        'reference_pack_id',
        'identity_lock_version',
        'manifest_json',
        'hash_payload_json',
        'identity_lock_hash',
        'status',
        'frozen_by',
        'frozen_at',
        'superseded_at',
        'metadata_json',
    ];

    protected $casts = [
        'identity_lock_version' => 'integer',
        'status' => IdentityLockStatus::class,
        'frozen_at' => 'immutable_datetime',
        'superseded_at' => 'immutable_datetime',
        'metadata_json' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(VideoProject::class, 'video_project_id');
    }

    public function approvedAnchor()
    {
        return $this->belongsTo(ApprovedAnchor::class, 'approved_anchor_id');
    }

    public function referencePack()
    {
        return $this->belongsTo(ReferencePack::class, 'reference_pack_id');
    }
}
