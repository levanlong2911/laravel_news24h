<?php

namespace App\Video\Llm;

/**
 * Cổng duyệt cho mọi cú gọi tốn phí.
 *
 * Mặc định của hệ thống là DenyByDefaultGate. Muốn tiêu tiền thì phải cấp gate
 * cho phép một cách tường minh — không có đường nào lỡ tay tiêu được.
 */
interface ApprovalGate
{
    public function allows(LlmRequest $request, float $estimatedCostUsd): bool;
}
