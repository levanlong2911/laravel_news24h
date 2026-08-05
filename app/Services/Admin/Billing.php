<?php

namespace App\Services\Admin;

/**
 * Request này có bị nhà cung cấp tính tiền không.
 *
 * Enum thay vì chuỗi trần: 'unknow', 'UNKNOWN', 'Yes' đều là lỗi chính tả im
 * lặng, mà cột này là thứ phép đối soát dựa vào — sai một giá trị là hỏng cả
 * phép trừ.
 *
 * Tách hẳn khỏi http_status vì đối soát cần đúng chiều này, không cần mã lỗi.
 */
enum Billing: string
{
    /** Đã sinh token — chắc chắn bị tính. */
    case Yes = 'yes';

    /** Bị chặn trước khi vào inference (400/429) hoặc lỗi phía server. */
    case No = 'no';

    /**
     * KHÔNG BIẾT — và đây là trường hợp đáng giá nhất của cả bảng.
     *
     * Timeout hoặc mất kết nối: model có thể đã sinh xong và bị tính tiền, mà
     * phía mình không bao giờ nhận được response để đọc usage. Không biết bao
     * nhiêu tiền, nhưng đếm được bao nhiêu lượt — đủ để giải thích độ lệch khi
     * đối soát với cost_report.
     */
    case Unknown = 'unknown';

    /**
     * curl trả 0 khi chưa kết nối được — đó không phải mã HTTP. Chuẩn hoá về
     * null trước khi gọi, để rơi vào Unknown thay vì No.
     */
    public static function fromHttpStatus(?int $httpStatus): self
    {
        return match (true) {
            $httpStatus === 200  => self::Yes,
            $httpStatus === null => self::Unknown,
            $httpStatus >= 400   => self::No,
            default              => self::Unknown,
        };
    }
}
