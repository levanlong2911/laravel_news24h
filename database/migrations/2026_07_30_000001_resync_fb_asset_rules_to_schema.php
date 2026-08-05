<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Quy khối FB trong phase3_generate về MỘT bản chuẩn duy nhất.
 *
 * ── Vì sao bản đầu của migration này hỏng ───────────────────────────────────
 *
 * Nó viết theo dạng "thay chuỗi A bằng chuỗi B", với A là nội dung mà nó tin
 * rằng seeder để lại. Chạy thật ngày 2026-08-05 thì throw ở 7/8 framework:
 * chúng không mang A, cũng không mang B, mà mang một trạng thái thứ ba — độ dài
 * đã là 150-250 (mới), nhưng vẫn cấm CTA (cũ), và có thêm "roster competition"
 * mà cả hai bản đều không có. Bảy cái giống nhau tới từng byte, nên đây là một
 * trạng thái có hệ thống chứ không phải ai đó sửa tay.
 *
 * Sai lầm gốc: migration mã hoá LỊCH SỬ dự kiến của database ("nó đã đi qua
 * migration nào") thay vì TRẠNG THÁI dự kiến ("dữ liệu bây giờ trông ra sao").
 * Lịch sử thì mỗi môi trường một khác và không kiểm chứng được từ trong code;
 * trạng thái thì đọc ra là biết.
 *
 * ── Mô hình thay thế ────────────────────────────────────────────────────────
 *
 * Nhận diện một số ít biến thể ĐÃ BIẾT, hội tụ tất cả về một bản chuẩn. Gặp
 * biến thể lạ thì DỪNG và in vân tay của nó ra, để người đọc quyết định — không
 * đoán, không im lặng bỏ qua.
 *
 *     legacy-60-150     ─┐
 *     interim-ban-cta    ├─→  canonical
 *     soft-cta           ─┘
 *     (lạ)              ──→  throw, kèm hash để đăng ký sau khi xem xét
 *
 * Đây là cùng chiến lược mà 2026_07_30_000005 dùng cho dòng gate FB ("xoá sạch
 * mọi dòng rồi chèn lại đúng hai dòng chuẩn"), và nó là cách duy nhất trong repo
 * này đã chạy đúng qua hai môi trường ở hai trạng thái khác nhau.
 *
 * ── Vì sao "roster competition" được giữ ────────────────────────────────────
 *
 * Nó chỉ có trong biến thể interim, không có trong seeder lẫn bản migration cũ.
 * Nhưng nó là nguồn tension có thật và hợp mảng thể thao, nên được nâng thành
 * canonical thay vì bị vứt — và PromptSystemSeeder cũng sửa theo, để không tồn
 * tại hai phiên bản của cùng một khối.
 *
 * ── Hai pha ─────────────────────────────────────────────────────────────────
 *
 * Soi toàn bộ trước, ghi sau. Nếu ghi ngay trong vòng lặp thì một framework lạ
 * ở cuối danh sách sẽ để lại vài framework đã ghi và vài cái chưa — đúng kiểu
 * trạng thái nửa vời đã sinh ra mớ này.
 */
return new class extends Migration
{
    // ── FB_IMAGE_TEXT ─────────────────────────────────────────────────────────

    private const IMAGE_LEGACY = <<<'TXT'
FB_IMAGE_TEXT:
- 1 sentence 60-90 chars. Use a strong active verb (steal, flip, crash, betray, collapse).
- Technique: Hook + Tension — reveal half the story, keep the best part hidden.
- Name the threat or rivalry — let reader feel stakes without knowing outcome.
- No emoji. Write in plain text only.
- GOOD: 'Miami Could Steal A.J. Brown Before Patriots Get a Shot'
-       'Southwest just made Alaska Airlines nervous'
-       'One phone call is about to change everything for Patriots fans.'
- BAD: Two separate fact statements. Literal numbers/dates. Restate the headline. Using emoji.
- Write in the same language as the article.
TXT;

    private const IMAGE_CANONICAL = <<<'TXT'
FB_IMAGE_TEXT:
- 1 sentence 70-100 chars. Use a strong active verb (steal, flip, crash, betray, collapse).
- Technique: Hook + Tension — reveal half the story, keep the best part hidden.
- MUST include the person's full name or team name — never use "a player", "a back", "the team", or any anonymous reference.
- Name the specific threat, rivalry, or irony — let reader feel stakes without knowing outcome.
- No emoji. Write in plain text only.
- GOOD: 'Green Bay is still betting on MarShawn Lloyd — who played one NFL game in two years.'
-       'Miami Could Steal A.J. Brown Before Patriots Get a Shot'
-       'Southwest just made Alaska Airlines nervous'
- BAD: "Green Bay is still banking on a back who played once." — anonymous, no proper name.
- BAD: Two separate fact statements. Literal numbers/dates. Restate the headline. Using emoji.
- Write in the same language as the article.
TXT;

    // ── FB_POST_CONTENT ───────────────────────────────────────────────────────

    /** Bản đời đầu: 60-150 ký tự, cấm CTA tuyệt đối. */
    private const POST_LEGACY = <<<'TXT'
FB_POST_CONTENT:
• 60-150 chars MAX. No bullet points. No lists. Plain paragraphs only.
• Structure: Hook → Tension → Hidden fact
• Reveal MAX 2 facts — keep the most specific or surprising fact hidden.
• Never use conclusion words: obvious, clear, certain, confirmed.
• "Changes everything" is BANNED — say WHAT changes instead.
• Name the threat or rivalry in the first line — never bury the conflict.
• No emoji. No URL. No hashtags. Same language as article.
• No CTA. Do NOT end with "Find out", "Read more", "Click", "Discover", or any call-to-action.
TXT;

    /**
     * Trạng thái tìm thấy trên 7/8 framework ngày 2026-08-05.
     *
     * Độ dài đã mới nhưng dòng đầu vẫn cấm CTA — trong khi Step 3 ngay bên dưới
     * lại BẮT BUỘC soft CTA. Mâu thuẫn nội bộ, và là lý do chính phải hội tụ.
     */
    private const POST_INTERIM = <<<'TXT'
FB_POST_CONTENT:
• 150-250 chars. Plain text only. No bullet points, emoji, URL, hashtags, or CTA. Same language as article.
• Formula: [Named person/team] + [Specific stake] + [Pressure/tension] + [Withheld outcome]
  Step 1 — Identify exactly who is affected and what they stand to lose or gain.
  Step 2 — Introduce pressure: deadline, injuries, roster competition, contract risk, rival move, or expectations.
  Step 3 — Soft CTA: end with a sentence that makes the reader feel they need the outcome.
  No explicit instructions ("Find out", "Read more", "Click", "Discover"). Choose one technique:
    Deadline        → "The window closes Thursday."
    Withheld ID     → "One team nobody expected is already in contact."
    Stakes fork     → "Sign him now — or lose him to a rival for nothing."
    Teased fact     → "Sirmans answered one question. His answer reframes the entire picture."
    Question        → "Which team made the call nobody expected?"
    Incomplete fact → "One number explains why Green Bay kept Lloyd over Wilson."
    Insider signal  → "What Sirmans said off-script tells you everything about his 2026 ceiling."
    Stakes gap      → "The roster math tells a very different story."
• Lean into uncertainty, upside, pressure, or controversy naturally.
• Be specific. Avoid generic suspense ("everything could change", "a decision to make").
• Avoid generic phrasing: "major update", "huge news", "shocking development", "fans stunned".
• GOOD: "Smith demanded a trade. His team has 48 hours and $8M to respond — or lose him for nothing."
• GOOD: "Lloyd is on the roster. Wilson is in Seattle. Training camp answers the only question left."
• BAD: "Big changes coming soon." — no name, no stake, no tension.
• BAD: "Find out what happens next." — explicit command, zero information.
• Do NOT use explicit CTAs: "Find out", "Read more", "Click", "Discover", or any direct instruction.
TXT;

    /** Trạng thái của travel_mobility: đã bỏ cấm CTA, nhưng chưa có roster competition. */
    private const POST_SOFT_CTA = <<<'TXT'
FB_POST_CONTENT:
• 150-250 chars. Plain text only. No bullet points, emoji, URL, hashtags. Same language as article.
• Formula: [Named person/team] + [Specific stake] + [Pressure/tension] + [Withheld outcome]
  Step 1 — Identify exactly who is affected and what they stand to lose or gain.
  Step 2 — Introduce pressure: deadline, injuries, rival move, contract risk, or expectations.
  Step 3 — Soft CTA: end with a sentence that makes the reader feel they need the outcome.
  No explicit instructions ("Find out", "Read more", "Click", "Discover"). Choose one technique:
    Deadline        → "The window closes Thursday."
    Withheld ID     → "One team nobody expected is already in contact."
    Stakes fork     → "Sign him now — or lose him to a rival for nothing."
    Teased fact     → "Sirmans answered one question. His answer reframes the entire picture."
    Question        → "Which team made the call nobody expected?"
    Incomplete fact → "One number explains why Green Bay kept Lloyd over Wilson."
    Insider signal  → "What Sirmans said off-script tells you everything about his 2026 ceiling."
    Stakes gap      → "The roster math tells a very different story."
• Lean into uncertainty, upside, pressure, or controversy naturally.
• Be specific. Avoid generic suspense ("everything could change", "a decision to make").
• Avoid generic phrasing: "major update", "huge news", "shocking development", "fans stunned".
• GOOD: "Smith demanded a trade. His team has 48 hours and $8M to respond — or lose him for nothing."
• GOOD: "Lloyd is on the roster. Wilson is in Seattle. Training camp answers the only question left."
• BAD: "Big changes coming soon." — no name, no stake, no tension.
• BAD: "Find out what happens next." — explicit command, zero information.
• Do NOT use explicit CTAs: "Find out", "Read more", "Click", "Discover", or any direct instruction.
TXT;

    /** Bản chuẩn: soft CTA của POST_SOFT_CTA + "roster competition" của POST_INTERIM. */
    private const POST_CANONICAL = <<<'TXT'
FB_POST_CONTENT:
• 150-250 chars. Plain text only. No bullet points, emoji, URL, hashtags. Same language as article.
• Formula: [Named person/team] + [Specific stake] + [Pressure/tension] + [Withheld outcome]
  Step 1 — Identify exactly who is affected and what they stand to lose or gain.
  Step 2 — Introduce pressure: deadline, injuries, roster competition, contract risk, rival move, or expectations.
  Step 3 — Soft CTA: end with a sentence that makes the reader feel they need the outcome.
  No explicit instructions ("Find out", "Read more", "Click", "Discover"). Choose one technique:
    Deadline        → "The window closes Thursday."
    Withheld ID     → "One team nobody expected is already in contact."
    Stakes fork     → "Sign him now — or lose him to a rival for nothing."
    Teased fact     → "Sirmans answered one question. His answer reframes the entire picture."
    Question        → "Which team made the call nobody expected?"
    Incomplete fact → "One number explains why Green Bay kept Lloyd over Wilson."
    Insider signal  → "What Sirmans said off-script tells you everything about his 2026 ceiling."
    Stakes gap      → "The roster math tells a very different story."
• Lean into uncertainty, upside, pressure, or controversy naturally.
• Be specific. Avoid generic suspense ("everything could change", "a decision to make").
• Avoid generic phrasing: "major update", "huge news", "shocking development", "fans stunned".
• GOOD: "Smith demanded a trade. His team has 48 hours and $8M to respond — or lose him for nothing."
• GOOD: "Lloyd is on the roster. Wilson is in Seattle. Training camp answers the only question left."
• BAD: "Big changes coming soon." — no name, no stake, no tension.
• BAD: "Find out what happens next." — explicit command, zero information.
• Do NOT use explicit CTAs: "Find out", "Read more", "Click", "Discover", or any direct instruction.
TXT;

    // ── Dòng gate trong QUALITY GATE ──────────────────────────────────────────

    private const GATE_IMAGE_LEGACY    = '[ ] FB image text: Hook + Tension, curiosity gap, ≤70 chars';
    private const GATE_IMAGE_CANONICAL = '[ ] FB image text: 70-100 chars, proper name required, Hook + Tension, no anonymous reference';

    private const GATE_POST_LEGACY    = '[ ] FB post: 60-150 chars, Hook→Tension→Hidden fact→CTA';
    private const GATE_POST_CANONICAL = '[ ] FB post: 150-250 chars, named + specific stake + pressure + withheld outcome, no generic phrases, no CTA';

    public function up(): void
    {
        $this->converge(
            imageVariants: [self::IMAGE_LEGACY, self::IMAGE_CANONICAL],
            imageTarget:   self::IMAGE_CANONICAL,
            postVariants:  [self::POST_LEGACY, self::POST_INTERIM, self::POST_SOFT_CTA, self::POST_CANONICAL],
            postTarget:    self::POST_CANONICAL,
            gateImage:     self::GATE_IMAGE_CANONICAL,
            gatePost:      self::GATE_POST_CANONICAL,
        );
    }

    /**
     * Hội tụ ngược về bản đời đầu. Đảo ngược được là yêu cầu có chủ ý — bản
     * migration cũ trước nữa để rollback thành ngõ cụt, và đó là một phần lý do
     * hai môi trường trôi khỏi nhau.
     */
    public function down(): void
    {
        $this->converge(
            imageVariants: [self::IMAGE_LEGACY, self::IMAGE_CANONICAL],
            imageTarget:   self::IMAGE_LEGACY,
            postVariants:  [self::POST_LEGACY, self::POST_INTERIM, self::POST_SOFT_CTA, self::POST_CANONICAL],
            postTarget:    self::POST_LEGACY,
            gateImage:     self::GATE_IMAGE_LEGACY,
            gatePost:      self::GATE_POST_LEGACY,
        );
    }

    // ── Máy hội tụ ────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $imageVariants  biến thể đã biết của khối FB_IMAGE_TEXT
     * @param  list<string>  $postVariants   biến thể đã biết của khối FB_POST_CONTENT
     */
    private function converge(
        array $imageVariants,
        string $imageTarget,
        array $postVariants,
        string $postTarget,
        string $gateImage,
        string $gatePost,
    ): void {
        $planned = [];
        $skipped = [];
        $unknown = [];

        // ── Pha 1: soi hết, chưa ghi gì ──
        foreach (DB::table('prompt_frameworks')->where('is_active', true)->orderBy('name')->get() as $framework) {
            $phase3 = $this->normalize((string) $framework->phase3_generate);

            // Framework không có mục FB nào — thêm mục mới là viết lại prompt,
            // vượt phạm vi một migration đồng bộ.
            if (!str_contains($phase3, 'FB_IMAGE_TEXT:')) {
                $skipped[] = $framework->name;
                continue;
            }

            $imageMatch = $this->matchVariant($phase3, $imageVariants);
            $postMatch  = $this->matchVariant($phase3, $postVariants);

            if ($imageMatch === null) {
                $unknown[] = $this->describeUnknown($framework->name, $phase3, 'FB_IMAGE_TEXT:', 'FB_POST_CONTENT:');
                continue;
            }

            if ($postMatch === null) {
                $unknown[] = $this->describeUnknown($framework->name, $phase3, 'FB_POST_CONTENT:', '═');
                continue;
            }

            $patched = str_replace($imageMatch, $imageTarget, $phase3);
            $patched = str_replace($postMatch, $postTarget, $patched);

            $patched = preg_replace('/\[ \] FB image text:[^\n]*/u', $gateImage, $patched);

            // Chỉ dòng gate đầu (độ dài + CTA). Dòng thứ hai "no bullet points, no
            // URL…" là ràng buộc format, giữ nguyên — migration đời trước khớp cả
            // hai nên ghi đè mất dòng format và để lại hai dòng y hệt nhau.
            $patched = preg_replace('/\[ \] FB post: (?!no bullet)[^\n]*/u', $gatePost, $patched);

            if ($patched !== (string) $framework->phase3_generate) {
                $planned[$framework->id] = ['name' => $framework->name, 'phase3' => $patched];
            }
        }

        // ── Pha 2: chỉ ghi khi KHÔNG còn cái nào lạ ──
        if ($unknown) {
            throw new RuntimeException(
                "Gặp biến thể khối FB chưa đăng ký — không đoán, không ghi gì cả.\n\n"
                . implode("\n", $unknown)
                . "\n\nXem nội dung thật, nếu đúng là một trạng thái hợp lệ thì thêm nó vào"
                . " danh sách biến thể của migration này rồi chạy lại."
            );
        }

        foreach ($planned as $id => $change) {
            DB::table('prompt_frameworks')->where('id', $id)->update(['phase3_generate' => $change['phase3']]);
        }

        echo '  hội tụ: ' . (implode(', ', array_column($planned, 'name')) ?: '(không cái nào cần đổi)') . "\n";

        if ($skipped) {
            echo '  bỏ qua (không có mục FB): ' . implode(', ', $skipped) . "\n";
        }
    }

    /**
     * Biến thể nào của khối đang có mặt trong $phase3. null = không cái nào.
     *
     * @param  list<string>  $variants
     */
    private function matchVariant(string $phase3, array $variants): ?string
    {
        foreach ($variants as $variant) {
            if (str_contains($phase3, $variant)) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * Mô tả một khối lạ đủ để người đọc quyết định: vân tay để đăng ký, và vài
     * dòng đầu để nhận ra nó là gì.
     */
    private function describeUnknown(string $framework, string $phase3, string $from, string $to): string
    {
        $start = strpos($phase3, $from);
        $end   = $start === false ? false : strpos($phase3, $to, $start + strlen($from));

        $block = $start === false
            ? '(không tìm thấy mục)'
            : rtrim(substr($phase3, $start, ($end === false ? null : $end - $start)));

        $preview = implode("\n      ", array_slice(explode("\n", $block), 0, 4));

        return sprintf(
            "  %s — khối bắt đầu bằng %s\n      sha256: %s  (%d ký tự)\n      %s",
            $framework,
            $from,
            substr(hash('sha256', $block), 0, 16),
            mb_strlen($block),
            $preview,
        );
    }

    private function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
};
