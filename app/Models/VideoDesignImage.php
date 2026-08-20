<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VideoDesignImage extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id', 'identity_id', 'image_code', 'image_type', 'state', 'slot_index',
        'proves_state', 'source_image_id', 'prompt_spec_json', 'prompt_sha256',
        'selected_artifact_id', 'status', 'revision', 'approved_at', 'approved_by',
        'metadata_json',
    ];

    protected $casts = [
        'slot_index' => 'integer',
        'revision' => 'integer',
        'prompt_spec_json' => 'array',
        'metadata_json' => 'array',
        'approved_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(VideoProject::class, 'project_id');
    }

    public function artifact()
    {
        return $this->belongsTo(VideoArtifact::class, 'selected_artifact_id');
    }

    public function source()
    {
        return $this->belongsTo(self::class, 'source_image_id');
    }

    public function next()
    {
        return $this->hasMany(self::class, 'source_image_id');
    }

    public function approver()
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }
}
