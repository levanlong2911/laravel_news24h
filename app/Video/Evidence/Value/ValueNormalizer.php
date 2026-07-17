<?php

namespace App\Video\Evidence\Value;

use App\Video\Evidence\ProvenanceLevel;

/**
 * Trả lời: "đoạn quote này có chống lưng cho giá trị kia không?"
 *
 * Hợp đồng cứng: `supports()` phải là **hàm thuần của $quote và $value**. Không
 * tra bảng, không knowledge base, không I/O, không AI. Nếu một implementation
 * cần biết bất cứ điều gì ngoài chính hai tham số đó, nó không phải normalizer —
 * nó là inference trá hình, và Semantic OS không nhận.
 *
 * Đó là toàn bộ ranh giới giữa NORMALIZED_VALUE và INFERRED:
 *   "101 metres" → 101.0         ✓ chỉ cần đọc chữ
 *   "Feadship"   → "Netherlands" ✗ cần biết Feadship là ai
 *
 * Xem docs/video/ARCHITECTURE.md §11.
 */
interface ValueNormalizer
{
    public function supports(string $quote, mixed $value): bool;

    public function level(): ProvenanceLevel;
}
