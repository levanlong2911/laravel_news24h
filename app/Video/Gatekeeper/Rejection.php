<?php

namespace App\Video\Gatekeeper;

/**
 * Một giả thuyết bị loại, kèm lý do gọi được tên.
 *
 * Reject phải ồn ào và đọc được. Một Gatekeeper âm thầm bỏ mất nửa bài báo sẽ
 * trông y hệt một Gatekeeper hoạt động tốt — cho tới khi video ra thiếu nội
 * dung mà không ai biết tại sao.
 */
final class Rejection
{
    public function __construct(
        public readonly string $subject,
        public readonly RejectionReason $reason,
        public readonly string $detail = '',
    ) {
    }

    public function describe(): string
    {
        return trim(sprintf('%s — %s. %s', $this->subject, $this->reason->explain(), $this->detail));
    }
}
