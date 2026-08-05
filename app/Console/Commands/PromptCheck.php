<?php

namespace App\Console\Commands;

use App\Services\Admin\PromptBuilderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Soi trạng thái prompt ĐANG CHẠY, không phải trạng thái lẽ ra phải chạy.
 *
 * ── Vì sao đọc DB chứ không đọc seeder/config ───────────────────────────────
 *
 * Câu hỏi lệnh này trả lời là "Claude thực sự nhận prompt gì?". Đường đi thật:
 *
 *     Seeder → Migration → DB → admin sửa qua form → Claude
 *
 * Chỉ mắt xích cuối mới quyết định. Đọc từ hằng số PHP thì seeder có thể đúng,
 * migration có thể đúng, báo cáo xanh — trong khi DB đã trôi đi chỗ khác từ lâu.
 * Đó đúng là loại lỗi lệnh này sinh ra để bắt, nên nó không được phép tự mắc.
 *
 * ── Bốn nguồn ───────────────────────────────────────────────────────────────
 *
 * Danh sách cấm nằm ở prompt_frameworks.phase3_generate. Nhưng phase3 còn hút
 * dữ liệu ngược vào chính nó từ ba bảng khác, và vi phạm đã được tìm thấy ở cả
 * ba trong lần quét 2026-08-04:
 *
 *     framework_content_types.structure_template   → {structure_template}
 *     framework_content_types.type_name            → dòng "CONTENT TYPE:"
 *     category_contexts.hook_style                 → {hook_style}
 *     category_contexts.tone_notes                 → {tone_notes}
 *
 * Đọc riêng từng bảng thì không bao giờ thấy — đó là lý do ba migration liên
 * tiếp mỗi cái chỉ chữa được phần mình tình cờ nhìn thấy.
 */
class PromptCheck extends Command
{
    protected $signature = 'prompt:check
        {--framework= : Chỉ soi một framework theo tên}
        {--fingerprint : In thêm vân tay phase3 để so hai môi trường}';

    protected $description = 'Đối chiếu từ cấm của phase3 với mọi dữ liệu được bơm ngược vào phase3';

    /** Parse được và có luật để soi. */
    private const PARSE_OK = 'ok';

    /** Parse được khối FORBIDDEN nhưng nó rỗng — đáng ngờ, không phải "sạch". */
    private const PARSE_EMPTY = 'empty';

    /** Không tìm thấy khối FORBIDDEN nào — nhiều khả năng định dạng đã đổi. */
    private const PARSE_ERROR = 'error';

    private int $violations  = 0;
    private int $parseFaults = 0;

    /** @var array<string, array{hash: string, banned: int, chars: int}> */
    private array $fingerprints = [];

    public function handle(): int
    {
        $frameworks = DB::table('prompt_frameworks')->orderBy('name')->get();

        if ($name = $this->option('framework')) {
            $frameworks = $frameworks->where('name', $name)->values();

            if ($frameworks->isEmpty()) {
                $this->error("Không có framework nào tên '{$name}'.");
                return self::FAILURE;
            }
        }

        foreach ($frameworks as $framework) {
            if ($this->isExcluded($framework->name)) {
                $this->line(sprintf('  <fg=gray>%-24s bỏ qua (ngoài phạm vi)</>', $framework->name));
                continue;
            }

            $this->checkFramework($framework);
        }

        return $this->summarize();
    }

    // ── Từng framework ────────────────────────────────────────────────────────

    private function checkFramework(object $framework): void
    {
        [$state, $banned] = $this->parseBanned((string) $framework->phase3_generate);

        if ($state !== self::PARSE_OK) {
            $this->parseFaults++;
            $this->line(sprintf('  <fg=red>%-24s %s</>', $framework->name, $this->faultMessage($state)));
            return;
        }

        $findings = array_merge(
            $this->scanContentTypes($framework, $banned),
            $this->scanContexts($framework, $banned),
            $this->scanLineEndings($framework),
            $this->scanPlaceholders($framework),
            $this->scanSections($framework),
            $this->scanGateLines($framework),
        );

        $this->violations += count($findings);
        $this->recordFingerprint($framework, $banned);

        $this->line(sprintf(
            '  %s%-24s %2d cụm cấm%s',
            $findings ? '<fg=yellow>' : '<fg=green>',
            $framework->name,
            count($banned),
            $findings ? '</>' : '   OK</>',
        ));

        foreach ($findings as $finding) {
            $this->line("     <fg=yellow>!</> {$finding}");
        }
    }

    // ── Bốn nguồn dữ liệu bơm vào phase3 ──────────────────────────────────────

    /** @param  list<string>  $banned  @return list<string> */
    private function scanContentTypes(object $framework, array $banned): array
    {
        $findings = [];

        $types = DB::table('framework_content_types')->where('framework_id', $framework->id)->get();

        foreach ($types as $type) {
            $columns = [
                'structure_template' => $type->structure_template,
                'type_name'          => $type->type_name,
            ];

            foreach ($columns as $column => $value) {
                foreach ($banned as $phrase) {
                    if ($this->contains($phrase, (string) $value)) {
                        $findings[] = sprintf('content_types.%-18s %-14s ← "%s"', $column, $type->type_code, $phrase);
                    }
                }
            }
        }

        return $findings;
    }

    /** @param  list<string>  $banned  @return list<string> */
    private function scanContexts(object $framework, array $banned): array
    {
        $findings = [];

        $contexts = DB::table('category_contexts as c')
            ->join('categories as g', 'g.id', '=', 'c.category_id')
            ->where('c.framework_id', $framework->id)
            ->select('g.slug', 'c.hook_style', 'c.tone_notes')
            ->get();

        foreach ($contexts as $context) {
            $columns = [
                'hook_style' => $context->hook_style,
                'tone_notes' => $context->tone_notes,
            ];

            foreach ($columns as $column => $value) {
                foreach ($banned as $phrase) {
                    if ($this->contains($phrase, (string) $value)) {
                        $findings[] = sprintf('contexts.%-23s %-14s ← "%s"', $column, $context->slug, $phrase);
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * Canh hồi quy của PromptFrameworkObserver::saving().
     *
     * Một hàng CRLF thì mọi migration khớp chuỗi nhiều dòng đều trượt nó trong
     * im lặng. Bệnh này đã xảy ra một lần với travel_mobility.
     *
     * @return list<string>
     */
    private function scanLineEndings(object $framework): array
    {
        $findings = [];

        foreach (['system_prompt', 'phase1_analyze', 'phase2_diagnose', 'phase3_generate'] as $field) {
            $count = substr_count((string) $framework->{$field}, "\r\n");

            if ($count > 0) {
                $findings[] = sprintf('%-32s %-14s ← %d dấu xuống dòng CRLF', "line_endings.{$field}", '', $count);
            }
        }

        return $findings;
    }

    /**
     * Placeholder lạ hoặc thiếu placeholder bắt buộc.
     *
     * Dùng thẳng inspectPlaceholders() — cùng contract mà PromptGuard dùng lúc
     * chạy và form admin dùng lúc lưu. Ba đường không thể lệch nhau.
     *
     * @return list<string>
     */
    private function scanPlaceholders(object $framework): array
    {
        $fields = [];

        foreach (array_keys(PromptBuilderService::PLACEHOLDERS) as $field) {
            $fields[$field] = $framework->{$field} ?? null;
        }

        $findings = [];

        foreach (PromptBuilderService::inspectPlaceholders($fields) as $field => $problem) {
            if ($problem['missing']) {
                $findings[] = sprintf('%-32s %-14s ← thiếu %s', "placeholder.{$field}", '', implode(' ', $problem['missing']));
            }

            if ($problem['unknown']) {
                $findings[] = sprintf('%-32s %-14s ← lạ %s', "placeholder.{$field}", '', implode(' ', $problem['unknown']));
            }
        }

        return $findings;
    }

    /**
     * Hình dạng tài liệu — tín hiệu độc lập với việc đọc danh sách từ cấm.
     *
     * Parser có thể đọc ra 20 cụm cấm mà tài liệu vẫn mất nguyên mục STEP 3.
     * Hai chuyện đó không liên quan gì nhau, nên phải soi riêng.
     *
     * @return list<string>
     */
    private function scanSections(object $framework): array
    {
        $phase3   = $this->normalize((string) $framework->phase3_generate);
        $findings = [];

        foreach ((array) config('prompt.check.required_sections', []) as $section) {
            if (stripos($phase3, $section) === false) {
                $findings[] = sprintf('%-32s %-14s ← thiếu mục "%s"', 'sections.phase3_generate', '', $section);
            }
        }

        return $findings;
    }

    /**
     * Dòng checklist trong QUALITY GATE: đủ số lượng và không trùng nhau.
     *
     * Lớp lỗi này lọt qua mọi kiểm tra khác. Migration 074940 khớp regex vào cả
     * hai dòng "[ ] FB post:" rồi ghi đè bằng cùng một chuỗi: từ cấm vẫn sạch,
     * placeholder vẫn đủ, mục vẫn đúng, parser vẫn chạy — nhưng prompt mất dòng
     * kiểm format và mang hai dòng y hệt nhau. Đếm và so trùng là đủ để bắt.
     *
     * @return list<string>
     */
    private function scanGateLines(object $framework): array
    {
        $phase3   = $this->normalize((string) $framework->phase3_generate);
        $findings = [];

        foreach ((array) config('prompt.check.gate_lines', []) as $prefix => $expected) {
            preg_match_all('/' . preg_quote($prefix, '/') . '[^\n]*/u', $phase3, $matches);

            $lines  = $matches[0];
            $unique = count(array_unique($lines));

            if (count($lines) !== $expected) {
                $findings[] = sprintf(
                    '%-32s %-14s ← "%s" có %d dòng, chờ %d',
                    'gate_lines.phase3_generate', '', $prefix, count($lines), $expected,
                );

                continue;
            }

            if ($unique !== count($lines)) {
                $findings[] = sprintf(
                    '%-32s %-14s ← "%s" có %d dòng trùng nhau',
                    'gate_lines.phase3_generate', '', $prefix, count($lines) - $unique,
                );
            }
        }

        return $findings;
    }

    // ── Parser ────────────────────────────────────────────────────────────────

    /**
     * Gom cụm cấm từ mọi khối có chữ FORBIDDEN: danh sách toàn cục và khối
     * FORBIDDEN của DOMAIN LAWS đều tính.
     *
     * Trả về [trạng thái, danh sách]. Trạng thái tách EMPTY khỏi ERROR có chủ ý:
     * "khối rỗng" và "không tìm thấy khối nào" là hai sự cố khác nhau, gộp lại
     * thì mất thông tin cần để sửa.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function parseBanned(string $phase3): array
    {
        $text   = $this->normalize($phase3);
        $blocks = array_filter(
            preg_split('/\n\n+/', $text),
            fn ($block) => stripos($block, 'FORBIDDEN') !== false,
        );

        if (!$blocks) {
            return [self::PARSE_ERROR, []];
        }

        $banned = [];

        foreach ($blocks as $block) {
            // Bỏ phần trong ngoặc đơn: đó là chỉ dẫn thay thế — '"crashed out"
            // (say "retired")' — nên "retired" là chữ ĐƯỢC dùng, không phải chữ
            // cấm. Không bỏ thì báo nhầm ngay ở framework motorsport.
            $clean = preg_replace('/\([^)]*\)/', '', $block);

            preg_match_all('/"([^"]+)"/', $clean, $matches);

            foreach ($matches[1] as $phrase) {
                $phrase = trim($phrase);

                if ($phrase !== '') {
                    $banned[mb_strtolower($phrase)] = $phrase;
                }
            }
        }

        return $banned
            ? [self::PARSE_OK, array_values($banned)]
            : [self::PARSE_EMPTY, []];
    }

    /**
     * Từ đơn khớp nguyên từ, cụm nhiều từ khớp chuỗi con.
     *
     * Không có ranh giới từ thì "cure" khớp "secure" và "rich" khớp "enriched" —
     * đủ nhiễu để báo cáo mất tác dụng.
     */
    private function contains(string $needle, string $haystack): bool
    {
        $haystack = $this->normalize($haystack);

        if (!str_contains($needle, ' ')) {
            return (bool) preg_match('/\b' . preg_quote($needle, '/') . '\b/iu', $haystack);
        }

        return stripos($haystack, $needle) !== false;
    }

    private function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    private function isExcluded(string $name): bool
    {
        foreach ((array) config('prompt.check.excluded_framework_prefixes', []) as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function faultMessage(string $state): string
    {
        return $state === self::PARSE_EMPTY
            ? 'PARSE_EMPTY — có khối FORBIDDEN nhưng không đọc ra cụm nào'
            : 'PARSE_ERROR — không tìm thấy khối FORBIDDEN nào, có thể định dạng đã đổi';
    }

    // ── Vân tay ───────────────────────────────────────────────────────────────

    /**
     * Vân tay phase3 — để trả lời "local và production có đang chạy cùng một
     * prompt không?", KHÔNG phải để báo động prompt đã đổi.
     *
     * Việc báo động đã có thứ làm tốt hơn: PromptFrameworkObserver bump version
     * và ghi snapshot đầy đủ vào prompt_versions mỗi lần admin sửa — lưu nội
     * dung cũ thật, hơn hẳn một chuỗi hash chỉ nói "có gì đó khác".
     *
     * Lỗ hổng còn lại là migration ghi bằng DB::table nên không sinh snapshot
     * nào, và đó đúng là cách hai môi trường trôi khỏi nhau — chuyện đã xảy ra
     * và được ghi lại trong docblock của 2026_07_30_000005. Hash băm trên bản đã
     * normalize nên CRLF không làm hai môi trường giống nhau trông thành khác.
     *
     * @param  list<string>  $banned
     */
    private function recordFingerprint(object $framework, array $banned): void
    {
        $phase3 = $this->normalize((string) $framework->phase3_generate);

        $this->fingerprints[$framework->name] = [
            'hash'   => substr(hash('sha256', $phase3), 0, 12),
            'banned' => count($banned),
            'chars'  => mb_strlen($phase3),
        ];
    }

    private function printFingerprints(): void
    {
        $this->newLine();
        $this->line('  <options=bold>Vân tay phase3</> (so giữa hai môi trường)');

        foreach ($this->fingerprints as $name => $print) {
            $this->line(sprintf(
                '  %-24s %s  %2d cụm cấm  %5d ký tự',
                $name,
                $print['hash'],
                $print['banned'],
                $print['chars'],
            ));
        }
    }

    // ── Kết luận ──────────────────────────────────────────────────────────────

    private function summarize(): int
    {
        if ($this->option('fingerprint')) {
            $this->printFingerprints();
        }

        $this->newLine();

        if ($this->parseFaults) {
            $this->error("Parser hỏng ở {$this->parseFaults} framework — báo cáo KHÔNG đáng tin.");
        }

        if ($this->violations) {
            $this->error("Còn {$this->violations} va chạm giữa dữ liệu và luật của phase3.");
        }

        if (!$this->parseFaults && !$this->violations) {
            $this->info('Sạch — không va chạm, không lỗi parser.');
            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
