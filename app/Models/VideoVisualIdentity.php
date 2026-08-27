<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VideoVisualIdentity extends Model
{
    use HasUuids;

    protected $table = 'video_visual_identities';

    protected $fillable = [
        'project_id', 'identity_type', 'name', 'version',
        'identity_json', 'identity_hash', 'locked_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'identity_json' => 'array',
        'locked_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(VideoProject::class, 'project_id');
    }

    public function designImages()
    {
        return $this->hasMany(VideoDesignImage::class, 'identity_id');
    }
}
