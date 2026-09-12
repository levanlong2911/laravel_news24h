<?php

declare(strict_types=1);

namespace App\Video\Identity\Models;

use App\Models\RenderQaRun;
use App\Models\VideoProject;
use App\Video\Identity\Enums\ReferencePackStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ReferencePack extends Model
{
    use HasUuids;

    protected $fillable = [
        'video_project_id',
        'approved_anchor_id',
        'canonical_concept_revision_id',
        'canonical_hash',
        'pack_version',
        'pack_json',
        'pack_hash',
        'status',
        'approved_by',
        'approved_at',
        'latest_qa_run_id',
        'metadata_json',
    ];

    protected $casts = [
        'pack_json' => 'array',
        'status' => ReferencePackStatus::class,
        'approved_at' => 'immutable_datetime',
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

    public function assets()
    {
        return $this->hasMany(ReferencePackAsset::class, 'reference_pack_id');
    }

    public function latestQaRun()
    {
        return $this->belongsTo(RenderQaRun::class, 'latest_qa_run_id');
    }
}
