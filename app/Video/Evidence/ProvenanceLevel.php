<?php

namespace App\Video\Evidence;

/**
 * Mức truy nguyên của một claim về Verified World Graph.
 *
 * Chú ý KHÔNG có case `Derived` — từ đó bị cấm trong toàn bộ codebase và có
 * Architecture Test canh. "Derived" mời gọi diễn giải rộng, và `Inferred` sẽ
 * chui vào qua đúng cửa đó. `NormalizedValue` nói rõ giới hạn: chỉ biến đổi
 * giá trị, không thêm tri thức.
 *
 * Xem docs/video/ARCHITECTURE.md §11.
 */
enum ProvenanceLevel: string
{
    /** Span xuất hiện nguyên văn. "101 metres" */
    case Direct = 'DIRECT';

    /** Khác format, cùng chuỗi. "Grey" → "grey" */
    case Normalized = 'NORMALIZED';

    /**
     * Hàm thuần của RIÊNG span đó, không dùng bất kỳ tri thức ngoài nào.
     * "101 metres" → 101.0 ✓   "€325M" → 325000000 ✓
     * "Feadship" → "Netherlands" ✗ (cần knowledge base → Inferred trá hình)
     */
    case NormalizedValue = 'NORMALIZED_VALUE';

    /**
     * LLM đoán. Semantic OS KHÔNG BAO GIỜ nhận.
     * Tồn tại như một case để Gatekeeper gọi tên được lý do reject.
     */
    case Inferred = 'INFERRED';

    public function isAcceptable(): bool
    {
        return $this !== self::Inferred;
    }
}
