<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VideoSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id', 'article_id', 'requested_by_admin_id', 'code',
        'status', 'planning_claimed_at', 'planning_claim_token', 'error_message',
        'cost_estimate_total', 'plan_revision',
    ];

    protected $casts = ['planning_claimed_at' => 'datetime'];

    // Giu nguyen khoa `renderplan_json` trong JSON tra ve cho Python: ke hoach da
    // doi cho sang bang video_render_plans, hop dong API thi khong doi.
    protected $appends = ['renderplan_json'];

    public function project()
    {
        return $this->belongsTo(VideoProject::class, 'project_id');
    }

    public function renderPlans()
    {
        return $this->hasMany(VideoRenderPlan::class, 'session_id');
    }

    public function latestRenderPlan()
    {
        return $this->hasOne(VideoRenderPlan::class, 'session_id')->latestOfMany('revision');
    }

    public function costEntries()
    {
        return $this->hasMany(VideoCostEntry::class, 'session_id');
    }

    // Ban ghi nao cung co the doc `$session->renderplan_json` nhu truoc khi cot
    // bi bo; ban moi nhat theo `revision`, khong theo thoi diem ghi.
    public function getRenderplanJsonAttribute(): ?array
    {
        return $this->latestRenderPlan?->plan_json;
    }

    // Cot `cost_actual` da bi bo. Cho nao can tong thi nap kem
    // withSum('costEntries as cost_actual', 'cost_usd'); khong nap thi ve 0
    // chu khong im lang tra null.
    public function getCostActualAttribute($value): float
    {
        return (float) ($value ?? $this->costEntries()->sum('cost_usd'));
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

    // null khi session do Python đẩy về qua POST /api/render-plans — chỉ lượt bấm
    // 🎬 trên màn hình mới có người yêu cầu.
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'requested_by_admin_id');
    }

    public function finals()
    {
        return $this->hasMany(VideoFinal::class, 'session_id');
    }
}
