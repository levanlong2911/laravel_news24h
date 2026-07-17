<?php

namespace App\Video\Evidence\Value;

use App\Video\Evidence\ProvenanceLevel;

/**
 * Con số được viết bằng chữ số, nguyên văn trong quote:
 *   "16 guests" → 16 · "delivered in 2020" → 2020 · "14.5- by 12-meter" → 14.5
 *
 * MeasurementNormalizer bắt buộc phải có đơn vị dính liền, nên nó mù trước mọi
 * con số không phải số đo — và cả trước số đo bị bỏ lửng đơn vị ("14.5- by
 * 12-meter" nghĩa là 14,5m nhân 12m, nhưng "14.5" không có đơn vị đi kèm). Cú
 * gọi Claude thật làm rụng 9 sự thật vì lý do này.
 *
 * KHÔNG cần YearNormalizer riêng: "2020" trong "delivered in 2020" chỉ là một số
 * viết bằng chữ số. Tách ra sẽ là hai class chạy y hệt một regex.
 * Ordinal ("166th") và số-viết-bằng-chữ ("two") thì CÓ class riêng, vì cơ chế
 * thật sự khác — không phải để chia cho gọn.
 *
 * ĐÁNH ĐỔI ĐÃ BIẾT: class này nới rộng thứ được qua. "€325 million" chứa 325 nên
 * nó chống lưng được cho BẤT KỲ thuộc tính nào bằng 325. Nhưng bảo đảm của
 * Gatekeeper CHƯA BAO GIỜ là "giá trị đúng với nghĩa của tên thuộc tính" —
 * `beam_meters` và `length_meters` đều khớp "meter" từ trước tới nay. Bảo đảm
 * luôn chỉ là "giá trị này được quote nói ra". Thứ bảo vệ ta là quote HẸP: xem
 * luật #3 trong instruction của Extractor.
 */
final class BareNumberNormalizer implements ValueNormalizer
{
    /**
     * Lookbehind/lookahead chặn việc xé một số thập phân: trong "14.5" phải khớp
     * "14.5", không được khớp "14" rồi coi như quote nói ra số 14.
     */
    private const PATTERN = '/(?<![\d.,])(\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?)(?![\d.,]*\d)/u';

    public function supports(string $quote, mixed $value): bool
    {
        if (! is_numeric($value) || is_bool($value)) {
            return false;
        }

        if (! preg_match_all(self::PATTERN, $quote, $matches)) {
            return false;
        }

        foreach ($matches[1] as $found) {
            if (abs((float) str_replace(',', '', $found) - (float) $value) < 1e-9) {
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
