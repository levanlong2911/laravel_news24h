<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Log;
use App\Services\Admin\GuardReason;

/**
 * POST Guard — chạy SAU khi Sonnet trả output.
 *
 * Kiểm tra:
 *   1. validateOutput()      — JSON parse được, có đủ fields bắt buộc
 *   2. checkHallucination()  — cross-check facts: title/content không chứa claim
 *                              hoàn toàn vắng mặt trong rawFacts
 *   3. isAcceptable()        — confidence score ≥ threshold → publish
 *                           — dưới threshold → đánh dấu human_review = true
 *
 * Không throw exception — trả về PostGuardResult để Job tự quyết định.
 */
class PostGuard
{
    // Hallucination confidence: 0.0 (nhiều suspect) → 1.0 (sạch)
    // Dưới ngưỡng này → human_review = true, KHÔNG tự publish
    private const CONFIDENCE_THRESHOLD = 0.70;

    // Các field bắt buộc trong JSON output của Sonnet
    private const REQUIRED_FIELDS = ['title', 'content'];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Chạy tất cả POST checks, trả về PostGuardResult.
     * Không throw — caller tự xử lý theo isAcceptable().
     *
     * @param  string  $sonnetOutput  Raw string từ Claude Sonnet
     * @param  string  $rawFacts      Facts từ Haiku (dùng để cross-check hallucination)
     */
    public function check(string $sonnetOutput, string $rawFacts): PostGuardResult
    {
        // Step 1: Parse JSON
        [$parsed, $parseReason] = $this->validateOutput($sonnetOutput);

        if ($parsed === null) {
            return PostGuardResult::invalid($parseReason);
        }

        // Step 2: Hallucination check
        $confidence = $this->checkHallucination($parsed, $rawFacts);

        $acceptable = $confidence >= self::CONFIDENCE_THRESHOLD;

        Log::info('[PostGuard] Result', [
            'confidence'  => $confidence,
            'acceptable'  => $acceptable,
            'title_chars' => mb_strlen($parsed['title'] ?? ''),
        ]);

        return PostGuardResult::make(
            parsed:     $parsed,
            confidence: $confidence,
            acceptable: $acceptable,
            reason:     $acceptable ? GuardReason::OK : "confidence {$confidence} below threshold " . self::CONFIDENCE_THRESHOLD,
        );
    }

    // ── Private — Validate ────────────────────────────────────────────────────

    /**
     * Parse JSON Sonnet output, kiểm tra required fields.
     * Trả về [array, null] nếu OK, [null, reason] nếu invalid.
     *
     * @return array{0: ?array, 1: string}
     */
    private function validateOutput(string $raw): array
    {
        // Sonnet đôi khi wrap trong markdown code block → strip trước
        $clean = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
        $clean = preg_replace('/\s*```$/', '', $clean);

        $data = json_decode($clean, true);

        if (!is_array($data)) {
            Log::warning('[PostGuard] JSON parse failed', ['raw' => substr($raw, 0, 300)]);
            return [null, GuardReason::JSON_INVALID];
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (empty($data[$field])) {
                Log::warning("[PostGuard] Missing required field: {$field}");
                return [null, GuardReason::MISSING_FIELDS];
            }
        }

        return [$data, GuardReason::OK];
    }

    // ── Private — Hallucination ───────────────────────────────────────────────

    /**
     * Cross-check: lấy các noun phrase nổi bật trong title + content,
     * kiểm tra xem chúng có xuất hiện trong rawFacts không.
     *
     * Confidence score:
     *   - Lấy tối đa MAX_CLAIMS claims từ output
     *   - Đếm bao nhiêu cái có trong rawFacts
     *   - score = found / total  (1.0 = tất cả đều có trong facts)
     *
     * Heuristic — không phải NLP — nhưng đủ để bắt hallucination rõ ràng
     * (tên người, đội, số liệu, năm không có trong source).
     */
    private function checkHallucination(array $parsed, string $rawFacts): float
    {
        $haystack = mb_strtolower($rawFacts);

        $claims = $this->extractClaims($parsed);

        if (empty($claims)) {
            // Không có entity cụ thể → không thể verify → neutral score
            return 0.80;
        }

        $found = 0;
        foreach ($claims as $claim) {
            if (str_contains($haystack, mb_strtolower($claim))) {
                $found++;
            }
        }

        $score = round($found / count($claims), 2);

        if ($score < self::CONFIDENCE_THRESHOLD) {
            Log::warning('[PostGuard] Low hallucination confidence', [
                'score'  => $score,
                'total'  => count($claims),
                'found'  => $found,
                'missed' => array_values(array_filter(
                    $claims,
                    fn($c) => !str_contains($haystack, mb_strtolower($c))
                )),
            ]);
        }

        return $score;
    }

    /**
     * Extract các claim cần verify:
     *   - Proper nouns (2 từ viết hoa liền nhau — tên người, đội)
     *   - Số liệu cụ thể (năm 20xx, số ≥ 2 chữ số)
     *   - Từ trong title (ngắn gọn, dễ verify nhất)
     *
     * Tối đa 15 claims — đủ để detect hallucination mà không chậm.
     */
    private function extractClaims(array $parsed): array
    {
        $text   = ($parsed['title'] ?? '') . ' ' . mb_substr($parsed['content'] ?? '', 0, 1500);
        $claims = [];

        // Proper nouns: 2 capitalized words in a row (không đầu câu)
        preg_match_all('/(?<![.!?]\s)(?<!\A)[A-Z][a-z]{2,} [A-Z][a-z]{2,}/', $text, $m);
        foreach ($m[0] as $noun) {
            $claims[] = $noun;
        }

        // Số cụ thể: năm (2020-2029) hoặc số có ≥ 2 chữ số
        preg_match_all('/\b20[0-9]{2}\b|\b[1-9][0-9]+\b/', $text, $m2);
        foreach ($m2[0] as $num) {
            $claims[] = $num;
        }

        // Deduplicate + limit
        return array_slice(array_unique($claims), 0, 15);
    }
}
