<?php

declare(strict_types=1);

namespace App\Video\Identity\Models;

use App\Models\RenderQaRun;
use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Video\Identity\Enums\AnchorApprovalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ApprovedAnchor extends Model
{
    use HasUuids;

    protected $fillable = [
        'video_project_id',
        'canonical_concept_revision_id',
        'canonical_hash',
        'projection_hash',
        'constraint_set_hash',
        'prompt_spec_hash',
        'render_id',
        'render_request_hash',
        'artifact_id',
        'artifact_hash',
        'artifact_storage_key',
        'mime_type',
        'width',
        'height',
        'qa_run_id',
        'qa_report_hash',
        'status',
        'approved_by',
        'approved_at',
        'metadata_json',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'status' => AnchorApprovalStatus::class,
        'approved_at' => 'immutable_datetime',
        'metadata_json' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(VideoProject::class, 'video_project_id');
    }

    public function render()
    {
        return $this->belongsTo(VideoRender::class, 'render_id');
    }

    public function qaRun()
    {
        return $this->belongsTo(RenderQaRun::class, 'qa_run_id');
    }

    public function referencePacks()
    {
        return $this->hasMany(ReferencePack::class, 'approved_anchor_id');
    }
}
