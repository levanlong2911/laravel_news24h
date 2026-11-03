<?php

namespace App\Services\Admin;

/**
 * Bước nào của pipeline đã sinh ra lượt gọi LLM này.
 *
 * ── Vì sao là enum, không phải chuỗi ────────────────────────────────────────
 *
 * Cùng lý do với Billing, nhưng hậu quả nặng hơn. `withPhase('WRTIE')` chạy
 * bình thường: không exception, không warning, không test nào bắt. Dòng sổ cái
 * vẫn được ghi. Dashboard mọc thêm một nhóm rác, và mọi con số nhóm theo phase —
 * chi phí, độ trễ, token, số lần retry — đều thiếu đi phần rơi vào nhóm đó.
 *
 * Nhìn dashboard thì vẫn "hợp lý". Đó là định nghĩa của thất bại im lặng, và là
 * đúng loại lỗi đã phải truy cả ngày 2026-08-05.
 *
 * Enum không làm code đẹp hơn — nó làm `Phase::WRTIE` không biên dịch được.
 *
 * Giá trị chuỗi giữ nguyên như cũ nên cột `phase` trong DB, dữ liệu đã ghi và
 * mọi truy vấn dashboard đều không đổi.
 */
enum Phase: string
{
    /** Haiku đọc bài gốc, trích facts (+ hooks nếu prompt gộp). */
    case FactExtraction = 'FACT_EXTRACTION';

    /** Haiku sinh 5 tiêu đề ứng viên — lượt từng bị bỏ quên khỏi giá thành. */
    case Hook = 'HOOK';

    /** Sonnet viết bài hoàn chỉnh. */
    case Write = 'WRITE';

    /** Sonnet sửa lại JSON hỏng — chỉ xảy ra khi PostGuard parse thất bại. */
    case WriteRetry = 'WRITE_RETRY';
}
