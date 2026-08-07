<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Một cảnh trong bản final: render nào, thứ tự thứ mấy, cắt từ đâu, dài bao lâu.
 *
 * Là BẢNG chứ không phải một mảng JSON trên `video_finals`, vì dựng phim cần
 * đúng những thứ JSON không giữ được: thứ tự, điểm vào, độ dài lấy ra. Và JSON
 * thì không join được, không có khoá ngoại, không chặn được một `render_id` đã
 * bị xoá khỏi hệ thống.
 */
class VideoFinalRender extends Model
{
    use HasUuids;

    protected $table = 'video_final_renders';

    protected $fillable = ['final_id', 'render_id', 'sequence_no', 'start_ms', 'duration_ms'];

    protected $casts = [
        'sequence_no' => 'integer',
        'start_ms' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function final()
    {
        return $this->belongsTo(VideoFinal::class, 'final_id');
    }

    public function render()
    {
        return $this->belongsTo(VideoRender::class, 'render_id');
    }
}
