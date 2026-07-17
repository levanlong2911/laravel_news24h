<?php

namespace App\Video\Llm;

/**
 * Cửa duy nhất Semantic OS nói chuyện với một mô hình ngôn ngữ.
 *
 * Semantic OS KHÔNG biết `app/Services/AI` tồn tại. Lý do không phải là code —
 * mà là dependency: nếu mai CMS sửa AIService thì Semantic OS không được phép
 * phải build lại. Adapter chịu cú va đó, Truth Layer không đổi.
 *
 * Nếu sau này phát hiện app/Services/AI đã lẫn prompt CMS, rewrite, dịch, SEO,
 * retry riêng — chỉ thay adapter.
 *
 * CHÚ Ý: "instruction", không phải "prompt". Đây là hai thứ khác nhau đang bị
 * trùng tên ở hầu hết dự án:
 *   - render prompt language ("cinematic", "8k", "24mm") — Laravel KHÔNG BAO GIỜ
 *     được biết. Đó là bất biến gốc §1.
 *   - extraction instruction — bảo Claude đọc bài báo. Truth Layer sống nhờ nó.
 * Đặt tên khác nhau để bất biến kia không phải chịu một ngoại lệ nào.
 */
interface LlmClient
{
    /**
     * @throws LlmUnavailable khi mô hình không gọi được
     * @throws \App\Video\Llm\ApprovalRequired khi cú gọi tốn phí chưa được duyệt
     */
    public function complete(LlmRequest $request): LlmResponse;
}
