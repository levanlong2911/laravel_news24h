<?php

namespace App\Video\Evidence\Value;

use App\Video\Evidence\ProvenanceLevel;

/**
 * Trả lời: "giá trị được khai báo có được CHÍNH đoạn quote này chống lưng không?"
 *
 * Đây là nửa thứ hai của Gatekeeper. Nửa thứ nhất (EvidenceIndex) hỏi "đoạn chữ
 * này có thật trong bài không?"; nửa này hỏi "đoạn chữ đó có nói lên giá trị này
 * không?". Thiếu nửa thứ hai thì LLM chỉ cần trích một câu có thật rồi gắn vào
 * bất kỳ giá trị nào nó muốn.
 *
 * Chạy từ chặt tới lỏng, dừng ở mức chặt nhất khớp được.
 */
final class ValueVerifier
{
    /** @var list<ValueNormalizer> */
    private array $normalizers;

    /**
     * @param list<ValueNormalizer>|null $normalizers
     */
    public function __construct(?array $normalizers = null)
    {
        // Chặt → lỏng. Số đo và tiền tệ đứng trước số trần: "101 metres" nên
        // được ghi nhận là số đo, không phải một con số vô danh tình cờ có mặt.
        $this->normalizers = $normalizers ?? [
            new ExactStringNormalizer(),
            new CaseFormatNormalizer(),
            new MeasurementNormalizer(),
            new CurrencyNormalizer(),
            new OrdinalNormalizer(),
            new BareNumberNormalizer(),
            new WordNumberNormalizer(),
            new ContainedPhraseNormalizer(), // lỏng nhất — để cuối
        ];
    }

    /**
     * Null = không normalizer nào thấy quote chống lưng cho giá trị này ⇒ LLM đã
     * suy luận thêm ⇒ INFERRED ⇒ reject.
     *
     * Bool cố tình không có normalizer nào nhận. Sự thật phủ định ("không có
     * radome") không đọc thẳng ra từ chữ được — nó cần hiểu "instead of", tức là
     * suy luận. Xem EvidenceGatekeeperTest.
     */
    public function verify(string $quote, mixed $value): ?ProvenanceLevel
    {
        foreach ($this->normalizers as $normalizer) {
            if ($normalizer->supports($quote, $value)) {
                return $normalizer->level();
            }
        }

        return null;
    }
}
