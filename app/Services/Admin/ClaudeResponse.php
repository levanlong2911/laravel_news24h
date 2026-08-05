<?php

namespace App\Services\Admin;

/**
 * Kết quả một lượt gọi Claude.
 *
 * inputTokens / outputTokens giữ lại để chỗ gọi cũ không gãy, nhưng chúng SUY RA
 * từ $usage chứ không phải nguồn riêng — tránh đúng thứ vừa sửa hôm nay: hai chỗ
 * cùng giữ một sự thật rồi trôi khỏi nhau. Cần tính tiền thì dùng $usage, nó có
 * đủ cả token cache lẫn model thật.
 */
final class ClaudeResponse
{
    public readonly int $inputTokens;
    public readonly int $outputTokens;

    public function __construct(
        public readonly string     $text,
        public readonly TokenUsage $usage,
    ) {
        $this->inputTokens  = $usage->inputTokens;
        $this->outputTokens = $usage->outputTokens;
    }
}
