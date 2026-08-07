<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Video hoàn chỉnh của MỘT session.
 *
 * Khoá theo session chứ không theo project: cùng một project render lại lần hai
 * là một BẢN FINAL KHÁC, không phải bản cũ bị ghi đè. Một bài viết có thể có
 * session A bị từ chối, session B được duyệt, session C sửa lại — mỗi cái một bản.
 */
class VideoFinal extends Model
{
    use HasUuids;

    protected $fillable = [
        'session_id', 'video_path', 'thumbnail_path', 'duration_seconds',
        'cost_total', 'status', 'error_message', 'published_at',
        'youtube_video_id', 'facebook_post_id', 'tiktok_video_id', 'instagram_video_id',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'cost_total' => 'float',
        'published_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(VideoSession::class, 'session_id');
    }

    /** Các cảnh đã ghép, ĐÚNG THỨ TỰ dựng phim. */
    public function cuts()
    {
        return $this->hasMany(VideoFinalRender::class, 'final_id')->orderBy('sequence_no');
    }

    public function renders()
    {
        return $this->belongsToMany(VideoRender::class, 'video_final_renders', 'final_id', 'render_id')
            ->withPivot(['sequence_no', 'start_ms', 'duration_ms'])
            ->orderBy('video_final_renders.sequence_no');
    }
}
