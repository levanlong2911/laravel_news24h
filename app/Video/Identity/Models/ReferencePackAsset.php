<?php

declare(strict_types=1);

namespace App\Video\Identity\Models;

use App\Models\RenderQaRun;
use App\Models\VideoRender;
use App\Video\Identity\Enums\ReferenceAssetStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ReferencePackAsset extends Model
{
    use HasUuids;

    protected $fillable = [
        'reference_pack_id',
        'view_id',
        'role',
        'order',
        'required',
        'camera_json',
        'additional_required_paths',
        'status',
        'render_id',
        'artifact_id',
        'artifact_hash',
        'artifact_storage_key',
        'mime_type',
        'width',
        'height',
        'qa_run_id',
        'qa_report_hash',
        'metadata_json',
    ];

    protected $casts = [
        'order' => 'integer',
        'required' => 'boolean',
        'camera_json' => 'array',
        'additional_required_paths' => 'array',
        'status' => ReferenceAssetStatus::class,
        'width' => 'integer',
        'height' => 'integer',
        'metadata_json' => 'array',
    ];

    public function referencePack()
    {
        return $this->belongsTo(ReferencePack::class, 'reference_pack_id');
    }

    public function render()
    {
        return $this->belongsTo(VideoRender::class, 'render_id');
    }

    public function qaRun()
    {
        return $this->belongsTo(RenderQaRun::class, 'qa_run_id');
    }
}
