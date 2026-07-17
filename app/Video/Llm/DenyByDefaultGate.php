<?php

namespace App\Video\Llm;

/**
 * Từ chối mọi thứ. Đây là mặc định của hệ thống.
 *
 * Một pipeline lỡ tay chạy trên 500 bài báo sẽ đốt tiền thật và làm điều đó rất
 * im lặng. Bắt buộc phải cấp gate cho phép một cách tường minh thì việc tiêu
 * tiền mới trở thành một hành động có chủ ý.
 */
final class DenyByDefaultGate implements ApprovalGate
{
    public function allows(LlmRequest $request, float $estimatedCostUsd): bool
    {
        return false;
    }
}
