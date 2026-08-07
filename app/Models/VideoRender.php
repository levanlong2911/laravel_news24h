<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * MỘT LƯỢT TIÊU TIỀN ĐÃ XẢY RA. Bất biến — không sửa, chỉ thêm dòng mới.
 *
 * Khác `VideoShot`: shot là TRẠNG THÁI HIỆN TẠI của kế hoạch (đổi được, ghi đè
 * được), render là SỰ KIỆN LỊCH SỬ. Trộn hai thứ đó đã sinh ra lỗi thật ngày
 * 2026-08-07: soạn lại session ghi đè `compiled_prompt` trong khi `artifact_path`
 * giữ nguyên, nên shot khoe một prompt không hề sinh ra tấm ảnh của nó.
 *
 * `sent_prompt` là NGUYÊN VĂN chuỗi đã rời khỏi máy, không phải chuỗi trong kế
 * hoạch. Hai thứ được phép khác nhau — compiler cắt bớt, ghép thêm, ẩn danh tên
 * riêng — và cái quyết định bức ảnh là cái cuối cùng.
 */
class VideoRender extends Model
{
    use HasUuids;

    protected $fillable = [
        'shot_id', 'attempt_no', 'render_kind', 'provider', 'model',
        'sent_prompt', 'prompt_sha256', 'request_sha256', 'negative_prompt',
        'source_render_id', 'source_kind', 'requires_state', 'proves_state',
        'artifact_path', 'artifact_dir', 'width', 'height', 'duration_ms', 'bytes',
        'cost_usd', 'provider_ms', 'status', 'error_message',
        'proof_method', 'proof_verified',
    ];

    protected $casts = [
        'attempt_no' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration_ms' => 'integer',
        'bytes' => 'integer',
        'cost_usd' => 'float',
        'provider_ms' => 'integer',
        'proof_verified' => 'boolean',
    ];

    public function shot()
    {
        return $this->belongsTo(VideoShot::class, 'shot_id');
    }

    /** Tấm ảnh đã đẻ ra lượt render này — null nếu sinh từ chữ. */
    public function sourceRender()
    {
        return $this->belongsTo(self::class, 'source_render_id');
    }

    /** Những lượt render đã dùng lượt này làm ảnh nguồn. */
    public function derivedRenders()
    {
        return $this->hasMany(self::class, 'source_render_id');
    }
}
