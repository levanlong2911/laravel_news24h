<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoReferenceCheck
{
    public const VERSION = 'moto-reference-check-v6';

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNSCORED = 'UNSCORED';

    public const READY = 'ready';

    public const NEEDS_CONFIRM = 'needs_confirm';

    public const NEEDS_CHECK = 'needs_check';

    public const FAILED = 'failed';

    private const LINE_MAX = 700;

    private const CELL_MAX = 9000;

    /** @var list<string> the words of a camera angle, a pixel position or a cell layout, which code writes */
    private const LOCKED = ['azimuth', 'elevation', 'degree', 'degrees', 'deg', 'pixel', 'pixels', 'px', 'grid', 'canvas'];

    /** @var list<string> people, tools and the set of a shot, which the keyframe adds and a reference cell never shows, unless the design of the cell's own parts uses the word */
    public const NOT_IN_CELL = ['hand', 'hands', 'glove', 'gloves', 'gloved', 'finger', 'fingers', 'builder', 'person', 'people', 'apron', 'boot', 'boots', 'grinder', 'hex key', 'cloth', 'rag', 'spanner', 'wrench', 'spark', 'sparks', 'vise', 'clamped', 'bench', 'jig', 'lift table', 'screen', 'monitor', 'cursor', 'road', 'asphalt'];

    /** @var list<string> colour words a line may use only when a cited fact or source line has them */
    private const COLOURS = ['red', 'blue', 'green', 'yellow', 'orange', 'purple', 'violet', 'pink', 'black', 'white', 'grey', 'gray', 'silver', 'gold', 'golden', 'brown', 'bronze', 'copper', 'chrome', 'beige', 'tan', 'cream', 'teal', 'turquoise', 'maroon', 'burgundy', 'navy', 'graphite'];

    /** @var list<string> material words a line may use only when a cited fact or source line has them */
    private const MATERIALS = ['steel', 'aluminium', 'aluminum', 'alloy', 'iron', 'titanium', 'magnesium', 'carbon', 'fibre', 'fiber', 'plastic', 'polymer', 'nylon', 'rubber', 'leather', 'vinyl', 'fabric', 'glass', 'acrylic', 'polycarbonate', 'wood', 'clay', 'foam', 'resin', 'brass', 'zinc', 'paper', 'cardboard', 'ceramic', 'chrome'];

    /** @var array<string, string> number words read as their digits */
    private const NUMBER_WORDS = ['one' => '1', 'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'ten' => '10', 'eleven' => '11', 'twelve' => '12', 'single' => '1', 'pair' => '2', 'both' => '2'];

    /** @var list<string> words that carry no fact of the part */
    private const PLAIN = [
        'a', 'an', 'the', 'and', 'or', 'but', 'of', 'to', 'in', 'on', 'at', 'by', 'for', 'from', 'with', 'without', 'into', 'onto', 'over', 'under', 'as', 'is', 'are', 'be', 'been', 'its', 'it', 'their', 'this', 'that', 'these', 'those',
        'each', 'every', 'all', 'any', 'no', 'not', 'only', 'also', 'still', 'yet', 'just', 'so', 'than', 'then', 'there', 'where', 'which', 'while', 'who', 'one', 'two', 'both', 'pair', 'single',
        'show', 'shows', 'shown', 'showing', 'seen', 'see', 'visible', 'clearly', 'clear', 'view', 'viewed', 'photo', 'photograph', 'studio', 'reference', 'image', 'cell', 'part', 'parts', 'subject', 'context',
        'about', 'around', 'near', 'along', 'across', 'between', 'beside', 'behind', 'above', 'below', 'beneath', 'through', 'toward', 'towards', 'within', 'against', 'up', 'down', 'out', 'off',
        'has', 'have', 'having', 'can', 'may', 'must', 'will', 'stay', 'stays', 'keep', 'keeps', 'kept', 'same', 'other', 'its', 'own', 'whole', 'entire', 'full', 'fully', 'partly',
    ];

    /**
     * @param  ?array<string, mixed>  $response
     * @param  array<string, mixed>  $context  the cells the run was sent with: their fingerprint, part ids and names, placements, state, checkpoint, draft lines, facts and the phrases no line may use
     * @param  list<array{code: string, path: string, message: string}>  $schema
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    public static function evaluate(?array $response, array $context, array $schema): array
    {
        if ($response === null) {
            return self::result(self::UNSCORED, null, 'Phản hồi không đọc được JSON.');
        }

        if (($response['format'] ?? null) !== MotoContract::REFERENCE_FORMAT) {
            return self::result(self::UNSCORED, null, 'Phản hồi không theo định dạng '.MotoContract::REFERENCE_FORMAT.'.');
        }

        $status = is_string($response['status'] ?? null) ? $response['status'] : null;
        $schemaLines = array_map(static fn (array $v): string => trim($v['path'].' '.$v['message']), $schema);

        if ($schemaLines !== []) {
            return self::result(self::FAIL, $status, null, $schemaLines);
        }

        if ($status === 'fail') {
            return self::result(self::FAIL, $status, 'Model trả status fail: '.implode('; ', (array) ($response['flags'] ?? [])));
        }

        $envelope = self::envelopeIssues($response, $context);
        $issues = $envelope;
        $warnings = [];

        foreach (self::cells($response, $context) as $cell) {
            $issues = [...$issues, ...array_map(static fn (string $line): string => "{$cell['cell_id']}: {$line}", array_diff($cell['issues'], $envelope))];

            if ($cell['gaps'] !== []) {
                $warnings[] = "{$cell['cell_id']}: AI báo thiếu dữ kiện — ".implode('; ', array_map(static fn (array $gap): string => $gap['missing'], $cell['gaps']));
            }

            if ($cell['review_status'] === self::NEEDS_CONFIRM) {
                $warnings[] = "{$cell['cell_id']}: AI viết lại hoặc thêm dòng — code không xác minh được nghĩa, người duyệt kiểm trước khi render.";
            }
        }

        return self::result($issues === [] ? self::PASS : self::FAIL, $status, null, [], $issues, $warnings);
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $context
     * @return list<array{cell_id: string, fingerprint: string, edit_status: string, review_status: string, lines: list<array{section: string, text: string}>, changes: list<array<string, mixed>>, left_out: list<array{fact_ref: string, what: string, reason: string, value: string}>, gaps: list<array{missing: string, reason: string}>, issues: list<string>}> every answered cell of the run: ready when it keeps the draft word for word and no draft line had to be rewritten, needs_confirm when a line was rewritten or added and passes every check, needs_check when the model names a gap, failed with the reasons otherwise
     */
    public static function cells(array $response, array $context): array
    {
        $cells = [];
        $envelope = self::envelopeIssues($response, $context);

        foreach ((array) ($response['data']['cells'] ?? []) as $answer) {
            $id = (string) ($answer['cell_id'] ?? '');
            $sent = $context['cells'][$id] ?? null;

            if (! is_array($sent) || isset($cells[$id])) {
                continue;
            }

            $draft = array_column((array) $sent['draft'], null, 'id');
            $facts = array_column((array) $sent['facts'], null, 'id');
            $issues = $envelope;
            $changes = [];
            $lines = [];
            $unchanged = ($answer['edit_status'] ?? null) === 'unchanged';
            $covering = [];
            $gaps = array_values(array_map(static fn (array $gap): array => ['missing' => (string) ($gap['missing'] ?? ''), 'reason' => (string) ($gap['reason'] ?? '')], (array) ($answer['gaps'] ?? [])));
            $leftOut = [];

            foreach ($unchanged ? [] : (array) ($answer['left_out'] ?? []) as $item) {
                $ref = (string) ($item['fact_ref'] ?? '');
                $fact = $facts[$ref] ?? null;

                if ($fact === null) {
                    $issues[] = "left_out dẫn dữ kiện {$ref} không có trong ô.";
                } elseif (($fact['time'] ?? null) !== MotoReferenceFacts::TARGET) {
                    $issues[] = "bỏ dữ kiện {$ref} (\"".mb_strimwidth((string) $fact['value'], 0, 80, '…').'") — chỉ dữ kiện mục tiêu mới được bỏ; quyết định của người trái dữ kiện này thì báo gap.';
                } else {
                    $leftOut[$ref] = ['fact_ref' => $ref, 'what' => (string) ($item['what'] ?? ''), 'reason' => (string) ($item['reason'] ?? ''), 'value' => (string) $fact['value']];
                }
            }

            if ($unchanged) {
                $lines = array_map(static fn (array $line): array => ['section' => (string) $line['section'], 'text' => (string) $line['text']], array_values($draft));
                $changes = array_map(static fn (array $line): array => ['kind' => 'kept', 'section' => $line['section'], 'text' => $line['text'], 'source_line_ids' => [$line['id']], 'fact_refs' => $line['fact_refs'], 'visual' => false, 'unsupported' => []], array_values($draft));

                foreach ($gaps === [] ? $draft : [] as $lineId => $line) {
                    if ($line['must_rewrite']) {
                        $issues[] = "giữ nguyên dòng nháp {$lineId} — dòng này phải viết lại cho đúng thời điểm của ô.";
                    }
                }
            }

            foreach ($unchanged ? [] : (array) ($answer['lines'] ?? []) as $index => $line) {
                $text = trim((string) ($line['text'] ?? ''));
                $section = (string) ($line['section'] ?? '');
                $sources = array_values(array_map('strval', (array) ($line['source_line_ids'] ?? [])));
                $refs = array_values(array_map('strval', (array) ($line['fact_refs'] ?? [])));
                $label = 'dòng '.($index + 1);

                foreach (array_diff($sources, array_keys($draft)) as $unknown) {
                    $issues[] = "{$label} dẫn dòng nháp {$unknown} không có trong ô.";
                }

                foreach (array_diff($refs, array_keys($facts)) as $unknown) {
                    $issues[] = "{$label} dẫn dữ kiện {$unknown} không có trong ô.";
                }

                if ($refs === []) {
                    $issues[] = "{$label} không dẫn dữ kiện nào.";
                }

                if (mb_strlen($text) === 0 || mb_strlen($text) > self::LINE_MAX) {
                    $issues[] = "{$label} dài ".mb_strlen($text).' ký tự; cho phép 1–'.self::LINE_MAX.'.';
                }

                if (! in_array($section, MotoReferencePlanner::SECTIONS, true)) {
                    $issues[] = "{$label} có mục ".json_encode($section, JSON_UNESCAPED_UNICODE).' không hợp lệ.';
                }

                $kept = count($sources) === 1 && isset($draft[$sources[0]]) && $draft[$sources[0]]['text'] === $text;

                if ($kept && $draft[$sources[0]]['must_rewrite']) {
                    $issues[] = "{$label} giữ nguyên dòng nháp {$sources[0]} — dòng này phải viết lại cho đúng thời điểm của ô.";
                }

                if (! $kept) {
                    $issues = [...$issues, ...array_map(static fn (string $problem): string => "{$label} {$problem}", self::cellIssues($text, $sent, $section))];
                }

                $pool = implode("\n", [
                    ...array_map(static fn (string $ref): string => (string) ($facts[$ref]['value'] ?? ''), $refs),
                    ...array_map(static fn (string $source): string => (string) ($draft[$source]['text'] ?? ''), $sources),
                    ...(array) $sent['names'],
                ]);
                $unsupported = $kept ? [] : self::unsupported($text, $pool);
                $issues = [...$issues, ...array_map(static fn (string $problem): string => "{$label} {$problem}", self::textIssues($text, $sent, $kept ? $text : $pool))];
                $changes[] = [
                    'kind' => $kept ? 'kept' : ($sources === [] ? 'added' : 'rewritten'),
                    'section' => $section,
                    'text' => $text,
                    'source_line_ids' => $sources,
                    'fact_refs' => $refs,
                    'visual' => ! $kept,
                    'unsupported' => $unsupported,
                ];
                $lines[] = ['section' => $section, 'text' => $text];

                foreach ($sources as $source) {
                    $covering[$source][] = ['text' => $text, 'refs' => $refs];
                }
            }

            if (! $unchanged) {
                foreach ($draft as $lineId => $line) {
                    $kept = $covering[$lineId] ?? [];

                    if ($kept === []) {
                        $issues[] = "bỏ mất dòng nháp {$lineId} — giữ, viết lại hoặc gộp mỗi dòng nháp vào ít nhất một dòng.";

                        continue;
                    }

                    $refs = array_merge([], ...array_column($kept, 'refs'));

                    foreach (array_diff((array) $line['fact_refs'], $refs, array_keys($leftOut)) as $lost) {
                        $issues[] = "dòng viết lại từ {$lineId} bỏ mất dữ kiện {$lost} (\"".mb_strimwidth((string) ($facts[$lost]['value'] ?? ''), 0, 80, '…').'") mà không ghi vào left_out.';
                    }

                    $excused = [];

                    foreach (array_intersect_key($leftOut, array_flip((array) $line['fact_refs'])) as $item) {
                        $excused = [...$excused, ...array_intersect(self::numbers($item['what']), self::numbers($item['value']))];
                    }

                    foreach (array_diff(self::numbers((string) $line['text']), self::numbers(implode("\n", array_column($kept, 'text'))), $excused) as $number) {
                        $issues[] = "dòng viết lại từ {$lineId} bỏ mất số {$number}.";
                    }
                }
            }

            $length = mb_strlen(implode("\n", array_column($lines, 'text')));

            if ($length > self::CELL_MAX) {
                $issues[] = "nội dung ô dài {$length} ký tự; tối đa ".self::CELL_MAX.'.';
            }

            $revised = array_filter($changes, static fn (array $change): bool => $change['kind'] !== 'kept') !== [];
            $cells[$id] = [
                'cell_id' => $id,
                'fingerprint' => (string) $sent['fingerprint'],
                'edit_status' => $unchanged ? 'unchanged' : 'revised',
                'review_status' => match (true) {
                    $issues !== [] => self::FAILED,
                    $gaps !== [] => self::NEEDS_CHECK,
                    $revised => self::NEEDS_CONFIRM,
                    default => self::READY,
                },
                'lines' => $lines,
                'changes' => $changes,
                'left_out' => array_values($leftOut),
                'gaps' => $gaps,
                'issues' => array_values(array_unique($issues)),
            ];
        }

        return array_values($cells);
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $context
     * @return list<string> what makes the whole answer unusable for every cell: another format, a status other than ok, another project or prompt version, or a cell list that is not the cells sent — each once, none missing, none unknown, in the order sent
     */
    public static function envelopeIssues(array $response, array $context): array
    {
        $issues = [];

        if (($response['format'] ?? null) !== MotoContract::REFERENCE_FORMAT) {
            $issues[] = 'Phản hồi không theo định dạng '.MotoContract::REFERENCE_FORMAT.'.';
        }

        if (($response['status'] ?? null) !== 'ok') {
            $issues[] = 'Phản hồi có status '.json_encode($response['status'] ?? null, JSON_UNESCAPED_UNICODE).', không phải ok.';
        }

        if (! is_string($context['project_id'] ?? null) || ($response['project_id'] ?? null) !== $context['project_id']) {
            $issues[] = 'project_id không khớp lượt chạy.';
        }

        if (! is_string($context['prompt_version'] ?? null) || ($response['prompt_version'] ?? null) !== $context['prompt_version']) {
            $issues[] = 'prompt_version là '.json_encode($response['prompt_version'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'; lượt chạy này dùng '.($context['prompt_version'] ?? 'null').'.';
        }

        $answered = array_map(static fn (mixed $cell): string => is_array($cell) ? (string) ($cell['cell_id'] ?? '') : '', array_values((array) ($response['data']['cells'] ?? [])));
        $expected = array_map('strval', array_keys((array) ($context['cells'] ?? [])));
        $twice = array_keys(array_filter(array_count_values($answered), static fn (int $count): bool => $count > 1));
        $missing = array_values(array_diff($expected, $answered));
        $unknown = array_values(array_diff($answered, $expected));

        if ($twice !== []) {
            $issues[] = 'Ô trả về hai lần: '.implode(', ', $twice).'.';
        }

        if ($missing !== []) {
            $issues[] = 'Thiếu ô đã gửi: '.implode(', ', $missing).'.';
        }

        if ($unknown !== []) {
            $issues[] = 'Ô không có trong lượt gửi: '.implode(', ', $unknown).'.';
        }

        if ($twice === [] && $missing === [] && $unknown === [] && $answered !== $expected) {
            $issues[] = 'Các ô trả về không theo thứ tự đã gửi ('.implode(', ', $expected).').';
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $reference
     * @param  array<string, array<string, mixed>>  $palette  the palette of the design by colour id
     * @return array{fingerprint: string, state: string, parts: list<string>, names: list<string>, locations: list<?string>, checkpoint: list<string>, draft: list<array<string, mixed>>, facts: list<array<string, mixed>>, banned: list<string>, absent: list<string>, design_text: string} what the check of one cell reads: its parts, names, placements and checkpoint, its draft and every fact in full, the phrases of colours and finishes no component of the cell carries at its state unless the draft code wrote already uses them, the parts not present and what the design says of its parts
     */
    public static function cellContext(array $reference, array $palette): array
    {
        $finished = [];
        $candidates = [];

        foreach ((array) $reference['parts'] as $part) {
            foreach ((array) $part['components'] as $component) {
                if ($component['status'] === MotoReferenceSurface::FINISHED) {
                    $finished[] = mb_strtolower((string) $component['design_finish']);

                    if ($component['color_id'] !== null) {
                        $finished[] = 'colour:'.$component['color_id'];
                    }

                    continue;
                }

                $candidates[] = (string) $component['design_finish'];
            }
        }

        foreach ($palette as $id => $colour) {
            if (in_array('colour:'.$id, $finished, true)) {
                continue;
            }

            $name = (string) ($colour['name'] ?? '');
            $candidates = [...$candidates, $name, (string) ($colour['hex'] ?? ''), (string) last(preg_split('/\s+/u', trim($name)) ?: [''])];
        }

        $draft = mb_strtolower(implode("\n", array_map(static fn (array $line): string => (string) $line['text'], (array) $reference['draft'])));
        $banned = [];

        foreach ($candidates as $phrase) {
            $phrase = trim($phrase);

            if (mb_strlen($phrase) < 3 || in_array(mb_strtolower($phrase), $finished, true) || self::mentions($draft, $phrase)) {
                continue;
            }

            $banned[mb_strtolower($phrase)] = $phrase;
        }

        $subjects = array_values(array_filter((array) $reference['parts'], static fn (array $part): bool => $part['role'] === 'subject'));

        return [
            'fingerprint' => (string) $reference['fingerprint'],
            'state' => (string) $reference['state'],
            'parts' => array_values(array_map(static fn (array $part): string => (string) $part['part'], (array) $reference['parts'])),
            'names' => array_values(array_map(static fn (array $part): string => (string) $part['name'], (array) $reference['parts'])),
            'locations' => array_values(array_unique(array_map(static fn (array $part): ?string => $part['location'], $subjects))),
            'checkpoint' => array_values(array_filter([$reference['checkpoint']['after'] ?? null, $reference['checkpoint']['before'] ?? null])),
            'draft' => array_values((array) $reference['draft']),
            'facts' => array_values(array_map(static fn (array $fact): array => array_intersect_key($fact, array_flip(['id', 'part_id', 'element', 'topic', 'time', 'scope', 'value', 'source_path'])), (array) $reference['facts'])),
            'banned' => array_values($banned),
            'absent' => array_values((array) $reference['requirement']['absent']),
            'design_text' => mb_strtolower(implode("\n", array_map(static fn (array $fact): string => (string) $fact['value'], array_filter((array) $reference['facts'], static fn (array $fact): bool => str_starts_with((string) $fact['source_path'], 'design.'))))),
        ];
    }

    /**
     * @param  array<string, mixed>  $cell
     * @return list<string> what a line the model wrote may not say in any cell: people, tools or the set of the shot, or outside the exclusions a part that is not there yet
     */
    private static function cellIssues(string $text, array $cell, string $section): array
    {
        $issues = [];
        $lower = mb_strtolower($text);

        foreach (self::NOT_IN_CELL as $word) {
            if (self::mentions($lower, $word) && ! self::mentions((string) $cell['design_text'], $word)) {
                $issues[] = "nêu \"{$word}\" — người, dụng cụ và bối cảnh thuộc keyframe, ô ảnh tham chiếu không có.";
            }
        }

        if ($section !== 'exclude') {
            foreach ((array) $cell['absent'] as $name) {
                if (self::mentions($lower, (string) $name)) {
                    $issues[] = "nhắc \"{$name}\", part chưa có ở thời điểm này.";
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $cell
     * @param  string  $pool  the text the line is drawn from: its cited facts, the draft lines it replaces and the names of the cell's parts
     * @return list<string> what a line may not say: a part id the cell does not list, another build state or work item, a camera angle, pixel position or layout, a number, colour or material none of its sources has, a placement its subjects do not have, a colour or finish of no component of the cell at its state
     */
    private static function textIssues(string $text, array $cell, string $pool): array
    {
        $issues = [];
        $lower = mb_strtolower($text);
        preg_match_all('/\bP\d{2}\b/u', $text, $parts);
        preg_match_all('/\bBS\d{2}\b/u', $text, $states);
        preg_match_all('/\bSC\d{2}-W\d+\b/u', $text, $items);

        foreach (array_unique(array_diff($parts[0], (array) $cell['parts'])) as $part) {
            $issues[] = "nhắc {$part}, part không thuộc ô.";
        }

        foreach (array_unique(array_diff($states[0], [(string) $cell['state']])) as $state) {
            $issues[] = "nhắc build state {$state}, không phải {$cell['state']} của ô.";
        }

        foreach (array_unique(array_diff($items[0], (array) $cell['checkpoint'])) as $item) {
            $issues[] = "nhắc công việc {$item}, không phải mốc của ô.";
        }

        $words = self::words($text);
        $poolStems = array_flip(array_map(self::stem(...), self::words($pool)));

        foreach (array_unique(array_intersect($words, self::LOCKED)) as $word) {
            if (! isset($poolStems[self::stem($word)])) {
                $issues[] = "nêu \"{$word}\" — góc máy, vị trí điểm ảnh và bố cục do code giữ.";
            }
        }

        if (str_contains($text, '°')) {
            $issues[] = 'nêu góc bằng độ — góc máy do code giữ.';
        }

        foreach (array_diff(self::numbers($text), self::numbers($pool)) as $number) {
            $issues[] = "nêu số {$number} không có trong dữ kiện dẫn.";
        }

        foreach (array_unique($words) as $word) {
            if (isset($poolStems[self::stem($word)])) {
                continue;
            }

            if (in_array($word, self::COLOURS, true)) {
                $issues[] = "nêu màu \"{$word}\" không có trong dữ kiện dẫn.";
            } elseif (in_array($word, self::MATERIALS, true)) {
                $issues[] = "nêu vật liệu \"{$word}\" không có trong dữ kiện dẫn.";
            }
        }

        $where = (array) config('video.moto.shots.prompt.work.locations');

        foreach (['on_motorcycle' => 'off_motorcycle', 'off_motorcycle' => 'on_motorcycle'] as $location => $other) {
            if ((array) $cell['locations'] === [$location] && isset($where[$other]) && self::mentions($lower, (string) $where[$other]) && ! self::mentions(mb_strtolower($pool), (string) $where[$other])) {
                $issues[] = "đặt part \"{$where[$other]}\" — vị trí của ô là {$where[$location]}.";
            }
        }

        foreach ((array) $cell['banned'] as $phrase) {
            if (self::mentions($lower, (string) $phrase)) {
                $issues[] = "nêu \"{$phrase}\", màu hoặc finish không có ở ô này tại {$cell['state']}.";
            }
        }

        return $issues;
    }

    /** @return list<string> the words of the line that name something none of its sources says, apart from plain words */
    private static function unsupported(string $text, string $pool): array
    {
        $stems = array_flip(array_map(self::stem(...), self::words($pool)));
        $missing = [];

        foreach (self::words($text) as $word) {
            if (mb_strlen($word) >= 3 && ! in_array($word, self::PLAIN, true) && ! isset(self::NUMBER_WORDS[$word]) && ! isset($stems[self::stem($word)])) {
                $missing[$word] = true;
            }
        }

        return array_keys($missing);
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        preg_match_all('/\p{L}+/u', mb_strtolower($text), $matches);

        return $matches[0];
    }

    private static function stem(string $word): string
    {
        foreach (['ies' => 'y', 'ing' => '', 'ed' => '', 'es' => '', 's' => ''] as $suffix => $replace) {
            if (mb_strlen($word) - mb_strlen($suffix) >= 3 && str_ends_with($word, $suffix)) {
                return mb_substr($word, 0, mb_strlen($word) - mb_strlen($suffix)).$replace;
            }
        }

        return $word;
    }

    /** @return list<string> every number of the text, written as digits, with number words read as digits */
    private static function numbers(string $text): array
    {
        preg_match_all('/(?<![\p{L}\d#.,-])\d+(?:[.,]\d+)?(?![\p{L}\d])/u', $text, $matches);
        $numbers = array_map(static function (string $number): string {
            $number = str_replace(',', '.', $number);

            return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : (ltrim($number, '0') ?: '0');
        }, $matches[0]);

        foreach (self::words($text) as $word) {
            if (isset(self::NUMBER_WORDS[$word]) && ! in_array($word, ['both', 'pair', 'single'], true)) {
                $numbers[] = self::NUMBER_WORDS[$word];
            }
        }

        return array_values(array_unique($numbers));
    }

    private static function mentions(string $lower, string $phrase): bool
    {
        $pattern = implode('\s+', array_map(static fn (string $word): string => preg_quote($word, '/'), preg_split('/\s+/u', mb_strtolower(trim($phrase))) ?: []));

        return $pattern !== '' && preg_match('/(?<![\p{L}\p{N}#])'.$pattern.'(?![\p{L}\p{N}])/u', $lower) === 1;
    }

    /** @param  list<string>  $schema */
    private static function result(string $verdict, ?string $status, ?string $error, array $schema = [], array $issues = [], array $warnings = []): array
    {
        return [
            'version' => self::VERSION,
            'format' => MotoContract::REFERENCE_FORMAT,
            'verdict' => $verdict,
            'status' => $status,
            'error' => $error,
            'schema' => $schema,
            'issues' => array_values(array_unique($issues)),
            'warnings' => array_values(array_unique($warnings)),
            'judge' => 'not_run',
        ];
    }
}
