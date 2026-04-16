<?php

namespace App\Services\Admin;

use App\Models\Article;
use Illuminate\Support\Facades\Log;

/**
 * PRE Guard — chạy TRƯỚC khi gọi Claude.
 *
 * Checks (theo thứ tự):
 *   1. validateInput()       — rawText không rỗng, không quá ngắn
 *   2. checkExactDuplicate() — SHA-256 content_hash đã tồn tại chưa
 *   3. checkNearDuplicate()  — SimHash Hamming distance ≤ 3 với bài gần đây
 *
 * Duplicate detection:
 *   - Exact:    sha256(normalized text) → content_hash (UNIQUE index, chặn race condition)
 *   - Near:     32-bit SimHash, Hamming distance ≤ 3 → gần giống nhau (same event, reworded)
 *
 * Race condition:
 *   PreGuard check trước insert. Nếu 2 job chạy đồng thời cùng pass check
 *   → MySQL UNIQUE index reject INSERT thứ 2 với DuplicateKey error
 *   → WriteArticleJob catch UniqueConstraintViolationException → skip sạch.
 */
class PreGuard
{
    private const MIN_CHARS = 200;

    // SimHash: 32-bit
    private const SIMHASH_BITS = 32;

    // Metric: Hamming distance (số bit khác nhau giữa 2 SimHash 32-bit)
    // Hoạt động NGƯỢC với similarity: distance thấp = giống nhau nhiều
    //   distance 0   = identical (100% similar)
    //   distance ≤ 3 = rất giống (~91%+ similar) → HARD skip
    //   distance ≤ 6 = khá giống (~81%+ similar) → SOFT flag review
    //   distance 10+ = khác nhau (~69% similar)  → bình thường
    private const SIMHASH_HARD = 3;  // Hamming ≤ 3 → hard skip   (~91%+ sim)
    private const SIMHASH_SOFT = 6;  // Hamming ≤ 6 → soft review (~81%+ sim)

    // Chỉ kiểm tra SimHash trong window này — tránh false positive bài cũ
    private const SIMHASH_DAYS  = 7;
    private const SIMHASH_LIMIT = 5000; // max rows load về PHP

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Chạy tất cả PRE checks. Throw PreGuardException nếu bất kỳ check fail.
     *
     * @throws PreGuardException
     */
    public function check(string $rawText, string $keyword): void
    {
        $this->validateInput($rawText, $keyword);
        $this->checkExactDuplicate($rawText);
        $this->checkNearDuplicate($rawText);
    }

    /**
     * SHA-256 của normalized content.
     * Gọi sau check() để lưu vào article khi insert.
     */
    public function hashContent(string $rawText): string
    {
        return hash('sha256', $this->normalize($rawText));
    }

    /**
     * 32-bit SimHash của normalized content.
     * Gọi sau check() để lưu vào article khi insert.
     */
    public function simhashContent(string $rawText): int
    {
        return $this->simhash($this->normalize($rawText));
    }

    // ── Private — Input Validation ────────────────────────────────────────────

    /** @throws PreGuardException */
    private function validateInput(string $rawText, string $keyword): void
    {
        if (empty(trim($rawText))) {
            throw new PreGuardException('rawText is empty', PreGuardException::REASON_EMPTY_INPUT);
        }

        $len = mb_strlen(trim($rawText));
        if ($len < self::MIN_CHARS) {
            throw new PreGuardException(
                "rawText too short ({$len} chars, min " . self::MIN_CHARS . ')',
                PreGuardException::REASON_TOO_SHORT
            );
        }

        if (empty(trim($keyword))) {
            throw new PreGuardException('keyword is empty', PreGuardException::REASON_EMPTY_INPUT);
        }

        Log::debug('[PreGuard] Input valid', ['keyword' => $keyword, 'chars' => $len]);
    }

    // ── Private — Exact Duplicate ─────────────────────────────────────────────

    /** @throws PreGuardException */
    private function checkExactDuplicate(string $rawText): void
    {
        $hash   = $this->hashContent($rawText);
        $exists = Article::where('content_hash', $hash)->exists();

        if ($exists) {
            Log::info('[PreGuard] Exact duplicate detected', ['hash' => $hash]);
            throw new PreGuardException(
                "Exact duplicate (hash: {$hash})",
                PreGuardException::REASON_DUPLICATE
            );
        }

        Log::debug('[PreGuard] No exact duplicate', ['hash' => $hash]);
    }

    // ── Private — Near Duplicate (SimHash) ───────────────────────────────────

    /**
     * Load SimHashes của bài trong SIMHASH_DAYS ngày gần nhất (tối đa SIMHASH_LIMIT).
     * Tìm minimum Hamming distance → quyết định hard skip hay soft review.
     *
     * Hamming distance (bit khác nhau, thấp = giống nhau):
     *   distance ≤ 3  (~91%+ similar) → throw REASON_NEAR_DUPLICATE  → skip hoàn toàn
     *   distance ≤ 6  (~81%+ similar) → throw REASON_SOFT_DUPLICATE  → flag human_review
     *   distance > 6                   → không làm gì, tiếp tục pipeline
     *
     * @throws PreGuardException
     */
    private function checkNearDuplicate(string $rawText): void
    {
        $newHash = $this->simhashContent($rawText);

        $existing = Article::where('created_at', '>=', now()->subDays(self::SIMHASH_DAYS))
            ->whereNotNull('content_simhash')
            ->limit(self::SIMHASH_LIMIT)
            ->pluck('content_simhash');

        $minDistance = PHP_INT_MAX;

        foreach ($existing as $stored) {
            $distance    = $this->hammingDistance($newHash, (int) $stored);
            $minDistance = min($minDistance, $distance);

            if ($minDistance <= self::SIMHASH_HARD) {
                break; // already at strictest threshold, no need to continue
            }
        }

        if ($minDistance <= self::SIMHASH_HARD) {
            Log::info('[PreGuard] Hard near-duplicate detected', ['distance' => $minDistance]);
            throw new PreGuardException(
                "Near-duplicate (Hamming: {$minDistance} ≤ " . self::SIMHASH_HARD . ')',
                PreGuardException::REASON_NEAR_DUPLICATE
            );
        }

        if ($minDistance <= self::SIMHASH_SOFT) {
            Log::info('[PreGuard] Soft near-duplicate detected', ['distance' => $minDistance]);
            throw new PreGuardException(
                "Soft near-duplicate (Hamming: {$minDistance} ≤ " . self::SIMHASH_SOFT . ')',
                PreGuardException::REASON_SOFT_DUPLICATE
            );
        }

        Log::debug('[PreGuard] No near-duplicate found', ['min_distance' => $minDistance]);
    }

    // ── Private — SimHash ─────────────────────────────────────────────────────

    /**
     * Normalize text trước khi hash — loại bỏ noise khiến false negative.
     */
    private function normalize(string $text): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($text)));
    }

    /**
     * 32-bit SimHash (Charikar, 2002).
     *
     * Algorithm:
     *   1. Tokenize → words (≥ 3 chars)
     *   2. Mỗi word: tính crc32 → duyệt 32 bits → v[i] += +1 hoặc -1
     *   3. Kết quả: bit i = 1 nếu v[i] > 0, ngược lại = 0
     *
     * Tính chất: 2 document giống nhau → SimHash gần nhau (Hamming nhỏ).
     */
    private function simhash(string $normalized): int
    {
        $words = array_filter(
            explode(' ', $normalized),
            fn($w) => mb_strlen($w) >= 3
        );

        $v = array_fill(0, self::SIMHASH_BITS, 0);

        foreach ($words as $word) {
            // crc32 → mask 0xFFFFFFFF → consistent 32-bit unsigned behavior trong PHP
            $hash = crc32($word) & 0xFFFFFFFF;
            for ($i = 0; $i < self::SIMHASH_BITS; $i++) {
                $v[$i] += ($hash >> $i & 1) ? 1 : -1;
            }
        }

        $result = 0;
        for ($i = 0; $i < self::SIMHASH_BITS; $i++) {
            if ($v[$i] > 0) {
                $result |= (1 << $i);
            }
        }

        return $result & 0xFFFFFFFF; // ensure unsigned 32-bit
    }

    /**
     * Đếm số bit khác nhau giữa 2 SimHash (Hamming distance).
     * Brian Kernighan's bit-count algorithm — O(k) với k = số bit = 1.
     */
    private function hammingDistance(int $a, int $b): int
    {
        $xor   = ($a ^ $b) & 0xFFFFFFFF;
        $count = 0;
        while ($xor !== 0) {
            $xor &= $xor - 1; // clear lowest set bit
            $count++;
        }
        return $count;
    }
}
