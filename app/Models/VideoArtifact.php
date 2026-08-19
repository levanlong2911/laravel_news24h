<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VideoArtifact extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id', 'session_id', 'scene_id', 'shot_id', 'render_id', 'design_cell_id',
        'artifact_type', 'role', 'storage_disk', 'storage_path', 'mime_type',
        'file_size', 'sha256', 'width', 'height', 'duration_ms', 'metadata_json',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration_ms' => 'integer',
        'metadata_json' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(VideoProject::class, 'project_id');
    }

    public function session()
    {
        return $this->belongsTo(VideoSession::class, 'session_id');
    }

    public function shot()
    {
        return $this->belongsTo(VideoShot::class, 'shot_id');
    }

    public function render()
    {
        return $this->belongsTo(VideoRender::class, 'render_id');
    }

    public function designCell()
    {
        return $this->belongsTo(VideoDesignCell::class, 'design_cell_id');
    }
}
