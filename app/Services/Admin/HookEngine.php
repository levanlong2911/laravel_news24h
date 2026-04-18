<?php

namespace App\Services\Admin;

use App\Models\FrameworkContentType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * HookEngine — Phase giữa Haiku và Sonnet.
 *
 * Flow:
 *   rawFacts (từ Haiku)
 *     → detectType()         — keyword fast path → LLM fallback nếu ambiguous
 *     → generateCandidates() — Claude Haiku → template fallback nếu parse lỗi
 *     → selectBest()         — score (virality + semantic) + diversity penalty
 *     → HookResult           — bestHook là anchor cho sonnetPrompt()
 *
 * Content phục vụ hook, không phải ngược lại.
 */
class HookEngine
{
    // Ngưỡng tin cậy keyword detection — dưới threshold → LLM classify
    private const DETECT_THRESHOLD = 2;

    // Ngưỡng similarity — trên đây → coi là duplicate, áp penalty
    private const SIMILARITY_THRESHOLD = 0.75;

    // Score bị trừ khi hook quá giống hook đã chọn
    private const DIVERSITY_PENALTY = 3;

    // Optimal headline length
    private const MIN_LENGTH = 45;
    private const MAX_LENGTH = 90;

    // Click-through power words — modifier/dramatic
    private const POWER_WORDS = [
        'record', 'breaks', 'broken', 'reveals', 'revealed', 'comeback',
        'upset', 'historic', 'shocking', 'dominant', 'stunning', 'surpasses',
        'ends', 'elite', 'first ever', 'all-time',
    ];

    // Outcome words — cho thấy có kết quả rõ ràng (semantic signal)
    private const OUTCOME_WORDS = [
        'wins', 'loses', 'signs', 'traded', 'injured', 'returns', 'fired',
        'hired', 'beats', 'defeats', 'retires', 'suspended', 'released',
        'drafted', 'cut', 'extends', 'joins',
    ];

    public function __construct(private ClaudeWriterService $claude) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Main entry point. Nhận rawFacts từ Haiku, trả về HookResult.
     *
     * @param  Collection  $contentTypes  FrameworkContentType[] đã load từ framework
     */
    public function resolve(
        string     $rawFacts,
        string     $keyword,
        string     $hookStyle,
        Collection $contentTypes,
    ): HookResult {
        $detectedType = $this->detectType($rawFacts, $contentTypes);

        /** @var FrameworkContentType|null $typeModel */
        $typeModel = $contentTypes->firstWhere('type_code', $detectedType);

        $candidates = $this->generateCandidates($typeModel, $hookStyle, $keyword, $rawFacts);

        if (empty($candidates)) {
            Log::warning('[HookEngine] All generation failed, using keyword fallback', [
                'keyword'       => $keyword,
                'detected_type' => $detectedType,
            ]);
            return new HookResult(
                bestHook:     $keyword,
                detectedType: $detectedType,
                candidates:   [],
                bestScore:    0,
                hookRank:     0, // 0 = fallback, không có candidates
            );
        }

        $best = $this->selectBest($candidates);

        // hook_rank: vị trí 1-based của bestHook trong candidates gốc
        // (trước khi diversity penalty thay đổi thứ tự — reflect "output thô" của model)
        $rankIndex = array_search($best['hook'], $candidates);
        $hookRank  = ($rankIndex !== false) ? (int) $rankIndex + 1 : 1;

        Log::debug('[HookEngine] Hook resolved', [
            'detected_type' => $detectedType,
            'best_hook'     => $best['hook'],
            'best_score'    => $best['score'],
            'hook_rank'     => $hookRank,
            'total_cands'   => count($candidates),
        ]);

        return new HookResult(
            bestHook:     $best['hook'],
            detectedType: $detectedType,
            candidates:   $candidates,
            bestScore:    $best['score'],
            hookRank:     $hookRank,
        );
    }

    // ── Fix 1: Hybrid detectType ──────────────────────────────────────────────

    /**
     * Bước 1: keyword fast path — đếm trigger matches.
     * Nếu top score < DETECT_THRESHOLD → ambiguous → LLM classify làm fallback.
     */
    private function detectType(string $rawFacts, Collection $contentTypes): string
    {
        $haystack = mb_strtolower($rawFacts);
        $scores   = [];

        /** @var FrameworkContentType $type */
        foreach ($contentTypes as $type) {
            if (!$type->is_active) {
                continue;
            }

            $count = 0;
            foreach ($type->trigger_keywords ?? [] as $kw) {
                if (str_contains($haystack, mb_strtolower($kw))) {
                    $count++;
                }
            }
            // Weighted score: keyword hits × applicability_score
            // Types with lower applicability_score (e.g. 0.7) are naturally de-ranked
            // without being fully disabled (is_active handles hard disabling)
            $scores[$type->type_code] = round($count * ($type->applicability_score ?? 1.0), 3);
        }

        if (empty($scores)) {
            // No active types in this framework — return first by sort_order
            $first = $contentTypes->sortBy('sort_order')->first();
            return $first?->type_code ?? 'breakthrough';
        }

        $topScore = max($scores);

        // Tie-breaking: nhiều type cùng score → dùng sort_order làm priority
        $tiedCodes = array_keys(array_filter($scores, fn($s) => $s === $topScore));

        $winner = $contentTypes
            ->whereIn('type_code', $tiedCodes)
            ->where('is_active', true)
            ->sortBy('sort_order')
            ->first()?->type_code ?? $tiedCodes[0];

        Log::debug('[HookEngine] Type detected via keywords', [
            'type' => $winner, 'score' => $topScore, 'tied' => count($tiedCodes),
        ]);

        return $winner;
    }

    // ── Fix 2: generateCandidates với template fallback ───────────────────────

    /**
     * Gọi Claude Haiku → parse JSON → nếu < 3 hooks → bổ sung từ template.
     * Luôn trả về ít nhất 3 candidates (template đảm bảo).
     */
    private function generateCandidates(
        ?FrameworkContentType $type,
        string                $hookStyle,
        string                $keyword,
        string                $rawFacts,
    ): array {
        $typeName    = $type?->type_name    ?? 'General';
        $toneProfile = implode(', ', $type?->tone_profile ?? ['neutral']);

        $prompt = <<<PROMPT
You are a professional headline writer for a news website.

CONTENT TYPE: {$typeName}
TONE: {$toneProfile}
HOOK STYLE: {$hookStyle}
KEYWORD: {$keyword}

FACTS:
---
{$rawFacts}
---

Generate exactly 5 compelling, accurate headlines for this article.
Rules:
- Each headline must be 45–90 characters
- Must be factually accurate to the facts above — no invention
- Vary the formats: question, statement, number-led, colon-separated
- No clickbait phrases like "You won't believe..."

Return ONLY a JSON array of 5 strings. No explanation, no markdown.
Example: ["Hook A", "Hook B", "Hook C", "Hook D", "Hook E"]
PROMPT;

        $candidates = [];
        $raw        = $this->claude->generate($prompt, 'haiku');

        if (!empty($raw) && preg_match('/\[.*\]/s', $raw, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                $parsed     = array_values(array_filter($decoded, 'is_string'));
                $candidates = array_slice($parsed, 0, 5); // cap ở 5, loại bỏ nếu Claude trả 6+
            }
        }

        if (count($candidates) < 3) {
            // Claude trả < 3 hoặc parse lỗi → supplement từ template (không retry)
            Log::info('[HookEngine] Claude returned < 3 hooks, supplementing with templates', [
                'claude_count' => count($candidates),
            ]);
            $templates  = $this->generateFromTemplate($keyword, $typeName, $rawFacts);
            $candidates = array_slice(array_unique(array_merge($candidates, $templates)), 0, 5);
        }

        return array_values($candidates);
    }

    /**
     * Template generator — fallback thuần rule-based, không gọi Claude.
     * Dùng khi Claude fail hoặc trả ít hook.
     */
    private function generateFromTemplate(string $keyword, string $typeName, string $rawFacts): array
    {
        // Lấy câu đầu tiên từ facts làm base
        $firstLine = trim(explode("\n", $rawFacts)[0] ?? '');
        $firstLine = preg_replace('/\s+/', ' ', $firstLine);
        if (mb_strlen($firstLine) > 80) {
            $firstLine = mb_substr($firstLine, 0, 77) . '...';
        }
        $base = $firstLine ?: $keyword;

        return [
            "{$keyword}: Full Breakdown and Analysis",
            "Breaking: {$base}",
            "{$typeName} Update — {$keyword} Latest News",
            "What We Know About {$keyword} Right Now",
            "{$keyword} — Key Facts and Details",
        ];
    }

    // ── Fix 3 + 4: selectBest với semantic scoring + diversity penalty ─────────

    /**
     * Score tất cả candidates, áp diversity penalty, chọn cao nhất.
     * Trả về ['hook' => string, 'score' => int] để caller có thể lưu bestScore.
     *
     * @return array{hook: string, score: int}
     */
    private function selectBest(array $candidates): array
    {
        // Tính điểm base cho từng hook
        $scored = array_map(
            fn($h) => ['hook' => $h, 'score' => $this->scoreHook($h)],
            $candidates
        );

        // Sắp xếp score cao → thấp
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        // Áp diversity penalty: hook quá giống hook đã "chấp nhận" → trừ điểm
        $accepted = [];
        foreach ($scored as &$item) {
            foreach ($accepted as $prev) {
                if ($this->jaccardSimilarity($item['hook'], $prev) > self::SIMILARITY_THRESHOLD) {
                    $item['score'] -= self::DIVERSITY_PENALTY;
                    break;
                }
            }
            $accepted[] = $item['hook'];
        }
        unset($item);

        // Re-sort sau penalty
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        return $scored[0] ?? ['hook' => $candidates[0], 'score' => 0];
    }

    /**
     * Virality + semantic scoring.
     *
     * Format signals:
     *   +3  bắt đầu bằng số (7 Ways, 3 Reasons...)
     *   +2  length 45–90 chars
     *   +1  chứa dấu hai chấm
     *   +1  câu hỏi (?)
     *   -1  quá ngắn < 30 chars
     *   -2  quá dài > 100 chars
     *
     * Click-through signals:
     *   +2  chứa power word (record, comeback, shocking...)
     *
     * Semantic signals (Fix 3):
     *   +2  entity chính — 2 capitalized words liền nhau (tên người/đội)
     *   +2  outcome rõ ràng (wins, loses, signs, injured...)
     *   +1  specificity — chứa số cụ thể hoặc năm
     */
    private function scoreHook(string $hook): int
    {
        $score = 0;
        $len   = mb_strlen($hook);
        $lower = mb_strtolower($hook);

        // ── Format signals ────────────────────────────────────────────────────
        if ($len >= self::MIN_LENGTH && $len <= self::MAX_LENGTH) {
            $score += 2;
        } elseif ($len < 30) {
            $score -= 1;
        } elseif ($len > 100) {
            $score -= 2;
        }

        if (preg_match('/^\d/', $hook)) {
            $score += 3;
        }

        if (str_contains($hook, ':')) {
            $score += 1;
        }

        if (str_ends_with(rtrim($hook), '?')) {
            $score += 1;
        }

        // ── Click-through ─────────────────────────────────────────────────────
        foreach (self::POWER_WORDS as $word) {
            if (str_contains($lower, $word)) {
                $score += 2;
                break; // 1 lần, tránh inflate
            }
        }

        // ── Semantic signals ──────────────────────────────────────────────────

        // Entity: 2 từ viết hoa liền nhau → tên người / đội / sự kiện
        if (preg_match('/[A-Z][a-z]+ [A-Z][a-z]+/', $hook)) {
            $score += 2;
        }

        // Outcome: có từ chỉ kết quả rõ ràng
        foreach (self::OUTCOME_WORDS as $word) {
            if (str_contains($lower, $word)) {
                $score += 2;
                break;
            }
        }

        // Specificity: chứa số (không tính số đứng đầu đã tính ở trên)
        // Dùng \b để tránh count số trong "45-90"
        if (preg_match('/\b(?:20\d{2}|\d{2,})\b/', $hook)) {
            $score += 1;
        }

        return $score;
    }

    /**
     * Jaccard similarity giữa 2 hook strings.
     * Dựa trên word set — không phân biệt hoa thường.
     * 0.0 = hoàn toàn khác, 1.0 = hoàn toàn giống.
     */
    private function jaccardSimilarity(string $a, string $b): float
    {
        $wordsA = array_unique(explode(' ', mb_strtolower(preg_replace('/[^a-zA-Z0-9 ]/', '', $a))));
        $wordsB = array_unique(explode(' ', mb_strtolower(preg_replace('/[^a-zA-Z0-9 ]/', '', $b))));

        // Loại bỏ stop words ngắn để không inflate similarity
        $stop   = ['a', 'an', 'the', 'is', 'in', 'on', 'at', 'to', 'for', 'of', 'and', 'or'];
        $wordsA = array_diff($wordsA, $stop);
        $wordsB = array_diff($wordsB, $stop);

        $intersection = count(array_intersect($wordsA, $wordsB));
        $union        = count(array_unique(array_merge($wordsA, $wordsB)));

        return $union > 0 ? $intersection / $union : 0.0;
    }
}
