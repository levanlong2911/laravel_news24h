<?php

namespace Tests\Video\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Architecture Tests — CI đỏ, không phải code review.
 *
 * Các bất biến ở docs/video/ARCHITECTURE.md không phải convention để review
 * nhắc nhau; chúng là test. Vi phạm = build fail.
 *
 * Quét bằng PHP tokenizer và BỎ QUA comment/docblock: luật áp lên CODE, không
 * áp lên tài liệu. Một docblock giải thích "Laravel không biết prompt là gì"
 * là hợp lệ; một biến tên $prompt thì không.
 */
class ArchitectureTest extends TestCase
{
    private const VIDEO_DIR = __DIR__ . '/../../../app/Video';

    /**
     * §1 — "Laravel không biết Prompt Language tồn tại."
     */
    public function test_laravel_is_prompt_blind(): void
    {
        $this->assertNoneOf([
            'prompt',
            'negative_prompt',
            'cinematic',
            'photorealistic',
            'masterpiece',
            'bokeh',
            'ultra[_ ]?realistic',
            'hyper[_ ]?realistic',
            '\b8k\b',
            '\b4k\b',
            'award[_ ]winning',
            '\bmm lens\b',
        ], 'Laravel phải hoàn toàn mù về prompt language. Xem ARCHITECTURE.md §1.');
    }

    /**
     * §3 — Ontology chung thay domain planner. Không switch(domain).
     */
    public function test_no_domain_branching(): void
    {
        $this->assertNoneOf([
            '\byachts?\b',
            '\bsuperyachts?\b',
            '\bsupercars?\b',
            '\bsavannahs?\b',
            '\bfeadship\b',
            '\$topic\s*===',
            'switch\s*\(\s*\$topic',
            '\$domain\s*===',
            'switch\s*\(\s*\$domain',
        ], 'Domain knowledge chỉ được tồn tại dưới dạng DỮ LIỆU, không bao giờ là nhánh code. Xem ARCHITECTURE.md §3.');
    }

    /**
     * §1 — Laravel emit Intent, Python quyết định Implementation.
     * Laravel không được biết kỹ thuật render hay provider nào tồn tại.
     */
    public function test_no_render_technique_or_provider(): void
    {
        $this->assertNoneOf([
            'ken[_ ]?burns',
            '\bkling\b',
            '\bflux\b',
            '\bveo\b',
            '\brunway\b',
            '\bpika\b',
            '\bsdxl\b',
            '\bhunyuan\b',
            '\bffmpeg\b',
            '\bcontent_type\b',
            '\bimage_to_video\b',
            '\bt2v\b',
        ], 'Laravel không được biết kỹ thuật render hay provider tồn tại. content_type đã bị xoá — dùng motion_intent. Xem ARCHITECTURE.md §1.');
    }

    /**
     * §4 — identity.semantic (owner/builder/price) là danh tính, không render
     * được. Nó phải dừng ở Laravel. Test này canh chiều ngược lại của luật
     * "Provider mù semantic": đảm bảo Laravel giữ nó tách khỏi attributes.
     */
    public function test_contract_keeps_identity_separate_from_attributes(): void
    {
        $plan = json_decode(
            file_get_contents(__DIR__ . '/../../../contracts/renderplan/v1.0/fixtures/moonrise.json'),
            true, 512, JSON_THROW_ON_ERROR,
        );

        foreach ($plan['world']['entities'] as $entity) {
            $semanticKeys = array_keys($entity['identity']['semantic'] ?? []);

            foreach ($semanticKeys as $key) {
                $this->assertArrayNotHasKey(
                    $key,
                    $entity['attributes'],
                    "Entity '{$entity['id']}': '{$key}' vừa nằm ở identity.semantic vừa ở attributes. "
                    . 'attributes chảy xuống ProviderIR — danh tính sẽ rò sang provider. Xem ARCHITECTURE.md §4.',
                );
            }
        }
    }

    /**
     * §11 — Gatekeeper deterministic 100%. Không AI, không I/O, không ngẫu nhiên.
     *
     * Đây là bất biến quan trọng nhất của Truth Layer. Ngày nào có người thấy
     * cần "hỏi LLM cho chắc" ở trong Gatekeeper thì toàn bộ kiến trúc đã chết:
     * Semantic OS không còn quyết định cái gì là sự thật nữa, LLM quyết định.
     */
    public function test_gatekeeper_never_calls_ai(): void
    {
        // Quét CODE, bỏ cả comment lẫn nội dung string: luật cấm phụ thuộc và
        // tính bất định, không cấm nhắc tên "LLM" trong một thông báo lỗi.
        // Một lời gọi AI không trốn được trong string literal.
        $this->assertNoneOf([
            '\bclaude\b',
            '\bopenai\b',
            '\banthropic\b',
            '\bllm\b',
            '\bgpt\b',
            '\bHttp::',
            '\bcurl_',
            '\bfile_get_contents\b',
            '\bmt_rand\b',
            '\brand\s*\(',
            '\bnow\s*\(',
            '\btime\s*\(',
        ], 'Gatekeeper phải deterministic 100%: cùng input luôn cho cùng output. '
            . 'Không gọi AI, không I/O, không ngẫu nhiên, không thời gian. Xem ARCHITECTURE.md §11.',
            __DIR__ . '/../../../app/Video/Gatekeeper',
            stripStrings: true);
    }

    /**
     * §11 — Từ `derived` bị cấm; dùng `NORMALIZED_VALUE`.
     *
     * "Derived" mời gọi diễn giải rộng — "Feadship → Netherlands" nghe cũng
     * derivable. Cái tên hẹp là hàng rào rẻ nhất chống lại việc INFERRED chui
     * vào qua cửa đó.
     */
    public function test_the_word_derived_is_banned(): void
    {
        $this->assertNoneOf(
            ['\bderived?\b', '\bDERIVED\b'],
            'Dùng NORMALIZED_VALUE thay cho DERIVED. Xem ARCHITECTURE.md §11.',
        );
    }

    /**
     * §1 — Truth ⊥ Planning. Sau khi Truth Layer đã freeze, đây là ranh giới
     * quan trọng nhất còn lại.
     *
     * Story Planner được đọc VerifiedWorldGraph, nhưng KHÔNG được chạm bất kỳ
     * provenance nào. VerifiedAttribute MANG Evidence bên trong, nên type system
     * không chặn được `$entity->attributes['x'][0]->evidence->quote` — chỉ có
     * test này chặn. Nếu một planner bắt đầu `if (str_contains($quote, 'award'))`
     * thì toàn bộ Truth Layer vừa xây thành vô nghĩa.
     *
     * Giới hạn đã biết: đây là grep, không phải type-level guarantee. Một
     * projection read-only sẽ chặn triệt để hơn, nhưng đó là abstraction chưa
     * trả rent (Rule 0) — hiện chỉ có một Planner, grep đủ.
     */
    public function test_planning_layer_cannot_reach_truth_provenance(): void
    {
        $banned = [
            '->evidence\b',
            '->quote\b',
            '->offset\b',
            '->rawSegments\b',
            '\bEvidenceIndex\b',
            '\bArticleNormalizer\b',
            '\bRawArticle\b',
            '\bhtml_sha256\b',
            '\bSourceFreeze\b',
            'App\\\\Video\\\\Evidence',
            'App\\\\Video\\\\Article',
            'App\\\\Video\\\\Extraction',
        ];

        $why = 'Planning Layer KHÔNG được chạm Truth provenance. Planner chỉ đọc '
            . 'VerifiedWorldGraph. Chạm Evidence/quote/offset/EvidenceIndex là xuyên thủng '
            . 'ranh giới Truth ⊥ Planning. Xem ARCHITECTURE.md §1.';

        // Mọi thư mục thuộc Planning Layer. Thêm phase mới (Intent, Asset...) thì
        // thêm vào đây — ranh giới áp cho toàn tầng, không riêng Story.
        foreach (['Story', 'Scene', 'Intent', 'Timeline', 'Editorial'] as $dir) {
            $this->assertNoneOf($banned, $why, __DIR__ . '/../../../app/Video/' . $dir);
        }
    }

    // ------------------------------------------------------------------

    /**
     * @param list<string> $bannedPatterns
     */
    private function assertNoneOf(array $bannedPatterns, string $why, ?string $dir = null, bool $stripStrings = false): void
    {
        $violations = [];

        $root = realpath(__DIR__ . '/../../../');

        foreach ($this->videoSourceFiles($dir) as $file) {
            $code = $this->stripComments(file_get_contents($file), $stripStrings);
            $relative = str_replace('\\', '/', substr(realpath($file), strlen($root) + 1));

            foreach (explode("\n", $code) as $lineNo => $line) {
                foreach ($bannedPatterns as $pattern) {
                    if (preg_match('/' . $pattern . '/i', $line)) {
                        $violations[] = sprintf('%s:%d — khớp /%s/: %s', $relative, $lineNo + 1, $pattern, trim($line));
                    }
                }
            }
        }

        $this->assertSame([], $violations, $why . "\n\nVi phạm:\n" . implode("\n", $violations));
    }

    /**
     * @return list<string>
     */
    private function videoSourceFiles(?string $dir = null): array
    {
        $dir ??= self::VIDEO_DIR;

        if (! is_dir($dir)) {
            return []; // thư mục chưa tồn tại — luật vẫn được canh sẵn
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Giữ nguyên số dòng (thay comment bằng dòng trống) để báo lỗi trỏ đúng chỗ.
     */
    private function stripComments(string $code, bool $stripStrings = false): string
    {
        $out = '';

        $blanked = [T_COMMENT, T_DOC_COMMENT];

        if ($stripStrings) {
            $blanked[] = T_CONSTANT_ENCAPSED_STRING;
            $blanked[] = T_ENCAPSED_AND_WHITESPACE;
            $blanked[] = T_INLINE_HTML;
        }

        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                [$id, $text] = $token;

                if (in_array($id, $blanked, true)) {
                    $out .= str_repeat("\n", substr_count($text, "\n"));
                    continue;
                }

                $out .= $text;
                continue;
            }

            $out .= $token;
        }

        return $out;
    }
}
