<?php

namespace App\Video\World;

use App\Video\Evidence\Evidence;

/**
 * Danh tính của một entity.
 *
 * `visualReferent` là phán đoán NGỮ NGHĨA: tên này có ghim xuống một hình dạng
 * cụ thể không? "Titanic" có, "Jan Koum" không.
 *
 * Nó KHÔNG khẳng định model AI biết cái tên đó — Laravel không có quyền biết
 * điều ấy. Việc dùng hay không dùng tên trong prompt là của ProviderPass bên
 * Python, dựa trên allowlist đã kiểm chứng theo từng provider.
 * ("Moonrise" là ca kinh điển: Laravel nói visual_referent=true và đúng, nhưng
 * Flux sẽ vẽ mặt trăng đang mọc.)
 *
 * Xem ARCHITECTURE.md §4.
 */
final class Identity
{
    /**
     * @param array<string, VerifiedAttribute> $semantic Danh tính/nguồn gốc —
     *        owner, builder, price. KHÔNG BAO GIỜ xuống ProviderIR.
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $visualReferent,
        public readonly Evidence $evidence,
        public readonly array $semantic = [],
    ) {
    }
}
