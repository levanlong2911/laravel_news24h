<?php

namespace App\Video\Evidence\Value;

use App\Video\Evidence\ProvenanceLevel;

/**
 * "101 metres" chống lưng cho 101 · "19.5 knots" cho 19.5 · "4,000 GT" cho 4000
 *
 * CHỈ đọc con số có sẵn trong chữ. Cố tình KHÔNG quy đổi đơn vị: "101 metres"
 * → 331 feet tuy deterministic nhưng cần biết hệ số 3.28084 — đó là tri thức
 * ngoài span, và một khi mở cửa cho bảng quy đổi thì cửa đó không đóng lại được.
 */
final class MeasurementNormalizer implements ValueNormalizer
{
    /**
     * `[\s\-]*` chứ không phải `\s*`: bài báo thật viết "99.95-meter",
     * "101-meter-long", "15.5-meter/50.8-foot" — gạch nối phổ biến ngang khoảng
     * trắng. Dùng `\s*` thì trượt sạch mọi số đo của bài.
     *
     * Đơn vị dài đứng trước đơn vị ngắn, vì alternation thử từ trái sang: "m"
     * đứng trước sẽ nuốt mất "meters".
     *
     * KHÔNG có "in" (inches): trong tiếng Anh nó trùng với giới từ, và
     * "delivered in 2020" sẽ thành 2020 inches. "inch"/"inches" thì không mơ hồ.
     */
    private const PATTERN = '/(-?\d{1,3}(?:,\d{3})*(?:\.\d+)?|-?\d+(?:\.\d+)?)[\s\-]*'
        . '(nautical miles|nautical mile|kilometres|kilometers|metres|meters|metre|meter|'
        . 'knots|knot|tonnes|tons|tonne|ton|feet|foot|inches|inch|'
        . 'mph|kph|km|gt|ft|kn|nm|m|t)\b/iu';

    public function supports(string $quote, mixed $value): bool
    {
        if (! is_numeric($value) || is_bool($value)) {
            return false;
        }

        if (! preg_match_all(self::PATTERN, $quote, $matches, PREG_SET_ORDER)) {
            return false;
        }

        foreach ($matches as $match) {
            $number = (float) str_replace(',', '', $match[1]);

            if (abs($number - (float) $value) < 1e-9) {
                return true;
            }
        }

        return false;
    }

    public function level(): ProvenanceLevel
    {
        return ProvenanceLevel::NormalizedValue;
    }
}
