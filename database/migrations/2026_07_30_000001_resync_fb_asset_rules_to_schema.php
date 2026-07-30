<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Đồng bộ luật FB trong phase3_generate với PromptBuilderService::FB_SCHEMA_APPEND.
 *
 * Vì sao cần migration này dù 074518 + 074940 đã mang đúng nội dung đó:
 * hai migration kia chạy khi prompt_frameworks còn rỗng (migrate trước db:seed),
 * nên chúng no-op — nhưng vẫn được ghi là đã apply, `migrate` sẽ không bao giờ
 * thử lại. Seeder chạy sau chèn text tiền-patch, để lại DB mâu thuẫn với schema:
 *
 *   schema  → fb_image_text 70-100 ký tự · fb_post_content 150-250 ký tự + soft CTA
 *   phase3  → fb_image_text 60-90  ký tự · fb_post_content 60-150  ký tự + CẤM CTA
 *
 * Claude nhận cả hai trong mỗi request nên độ dài và CTA ra không ổn định.
 *
 * Khác hai migration cũ ở hai điểm:
 *   1. down() đảo ngược được — không để rollback thành ngõ cụt lần nữa.
 *   2. Framework nào không thay được thì THROW, không im lặng bỏ qua. Chính kiểu
 *      no-op âm thầm đã tạo ra tình trạng này.
 */
return new class extends Migration
{
    private const IMAGE_OLD = <<<'TXT'
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

    private const IMAGE_NEW = <<<'TXT'
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

    private const POST_OLD = <<<'TXT'
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

    private const POST_NEW = <<<'TXT'
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

    private const GATE_IMAGE_OLD = '[ ] FB image text: Hook + Tension, curiosity gap, ≤70 chars';
    private const GATE_IMAGE_NEW = '[ ] FB image text: 70-100 chars, proper name required, Hook + Tension, no anonymous reference';

    private const GATE_POST_OLD = '[ ] FB post: 60-150 chars, Hook→Tension→Hidden fact→CTA';
    private const GATE_POST_NEW = '[ ] FB post: 150-250 chars, named + specific stake + pressure + withheld outcome, no generic phrases, no CTA';

    public function up(): void
    {
        $this->resync(
            self::IMAGE_OLD, self::IMAGE_NEW,
            self::POST_OLD,  self::POST_NEW,
            self::GATE_IMAGE_NEW, self::GATE_POST_NEW,
        );
    }

    public function down(): void
    {
        $this->resync(
            self::IMAGE_NEW, self::IMAGE_OLD,
            self::POST_NEW,  self::POST_OLD,
            self::GATE_IMAGE_OLD, self::GATE_POST_OLD,
        );
    }

    private function resync(
        string $imgFrom,
        string $imgTo,
        string $postFrom,
        string $postTo,
        string $gateImage,
        string $gatePost,
    ): void {
        $updated = [];
        $skipped = [];
        $failed  = [];

        foreach (DB::table('prompt_frameworks')->where('is_active', true)->get() as $fw) {
            // Framework không có mục FB nào (sports_general) — bỏ qua. Thêm mục mới
            // là viết lại prompt, vượt phạm vi một migration đồng bộ.
            if (!str_contains($fw->phase3_generate, 'FB_IMAGE_TEXT:')) {
                $skipped[] = $fw->name;
                continue;
            }

            $phase3 = str_replace($imgFrom,  $imgTo,  $fw->phase3_generate);
            $phase3 = str_replace($postFrom, $postTo, $phase3);

            $phase3 = preg_replace('/\[ \] FB image text:[^\n]*/u', $gateImage, $phase3);

            // Chỉ dòng gate đầu (độ dài + CTA). Dòng thứ hai "no bullet points, no URL…"
            // là ràng buộc format, giữ nguyên — migration cũ khớp cả hai nên nhân đôi dòng.
            $phase3 = preg_replace('/\[ \] FB post: (?!no bullet)[^\n]*/u', $gatePost, $phase3);

            if (!str_contains($phase3, $imgTo) || !str_contains($phase3, $postTo)) {
                $failed[] = $fw->name;
                continue;
            }

            if ($phase3 !== $fw->phase3_generate) {
                DB::table('prompt_frameworks')
                    ->where('id', $fw->id)
                    ->update(['phase3_generate' => $phase3]);

                $updated[] = $fw->name;
            }
        }

        echo '  updated: ' . (implode(', ', $updated) ?: '(none)') . "\n";
        if ($skipped) {
            echo '  skipped (no FB section): ' . implode(', ', $skipped) . "\n";
        }

        if ($failed) {
            throw new RuntimeException(
                'Không thay được khối FB ở: ' . implode(', ', $failed)
                . '. Nội dung phase3 đã lệch khỏi bản migration này dự kiến — kiểm tra tay trước khi chạy lại.'
            );
        }
    }
};
