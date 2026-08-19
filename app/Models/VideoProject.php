<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VideoProject extends Model
{
    use HasUuids, SoftDeletes;

    // `subject_id` giữ lại vì VideoProjectRepository còn đọc; `article_id` là cột
    // đúng tên cho cùng giá trị đó, thêm 2026-08-18.
    protected $fillable = ['title', 'article_id', 'admin_id', 'project_type',
        'active_session_id', 'subject_id', 'design_ref',
        'design_id', 'metadata_json'];

    protected $casts = ['metadata_json' => 'array'];

    public function sessions()
    {
        return $this->hasMany(VideoSession::class, 'project_id');
    }

    public function article()
    {
        return $this->belongsTo(Article::class);
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function costEntries()
    {
        return $this->hasMany(VideoCostEntry::class, 'project_id');
    }

    public function planningStages()
    {
        return $this->hasMany(VideoPlanningStage::class, 'project_id');
    }

    public function designCells()
    {
        return $this->hasMany(VideoDesignCell::class, 'project_id');
    }

    public function activeSession()
    {
        return $this->belongsTo(VideoSession::class, 'active_session_id');
    }
}
