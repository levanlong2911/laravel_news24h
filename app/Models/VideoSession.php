<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VideoSession extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'article_id', 'code', 'renderplan_json', 'status', 'cost_estimate_total', 'cost_actual'];

    protected $casts = ['renderplan_json' => 'array'];

    public function project()
    {
        return $this->belongsTo(VideoProject::class, 'project_id');
    }

    public function shots()
    {
        return $this->hasMany(VideoShot::class, 'session_id')->orderBy('beat')->orderBy('shot_code');
    }

    // Session = MỘT LƯỢT SẢN XUẤT của một bài. Trước đây nối bằng phép so chuỗi
    // `video_projects.name == articles.title` — gãy ngay khi ai đó sửa tiêu đề.
    public function article()
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function finals()
    {
        return $this->hasMany(VideoFinal::class, 'session_id');
    }
}
