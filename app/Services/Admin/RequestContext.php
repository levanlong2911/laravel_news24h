<?php

namespace App\Services\Admin;

/**
 * Ngữ cảnh nghiệp vụ đi kèm MỘT lượt gọi LLM, để ghi vào sổ cái.
 *
 * ── Vì sao là tham số chứ không phải trạng thái của service ─────────────────
 *
 * ClaudeWriterService KHÔNG được bind singleton: ArticlePipelineService và
 * HookEngine mỗi bên nhận một instance riêng. Đặt context lên service thì set ở
 * pipeline xong lượt gọi của HookEngine vẫn rỗng — mà đó chính là lượt từng bị
 * bỏ quên cả về token lẫn chi phí. Context phải đi theo lời gọi.
 *
 * ── Vì sao gói thành object thay vì thêm tham số ────────────────────────────
 *
 * generate() đã có prompt/model/system. Thêm articleId, pipelineRunId, phase,
 * rồi mai là campaign, locale, userId... là bùng nổ tham số. Gói lại thì thêm
 * trường về sau không phải sửa chữ ký hàm ở mọi chỗ gọi.
 *
 * phase là thứ DUY NHẤT đổi giữa các lượt trong cùng một bài, nên có withPhase()
 * để dẫn xuất bản sao — object vẫn bất biến.
 */
final class RequestContext
{
    public function __construct(
        public readonly ?string $articleId     = null,
        public readonly ?string $pipelineRunId = null,
        public readonly ?Phase  $phase         = null,
    ) {}

    /** Bản sao cho một phase khác — dùng khi cùng bài nhưng khác bước. */
    public function withPhase(?Phase $phase): self
    {
        return new self(
            articleId:     $this->articleId,
            pipelineRunId: $this->pipelineRunId,
            phase:         $phase,
        );
    }
}
