<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoReferenceCheck
{
    /** v8: a cell fails only for a finding that holds it (RULE or a requirement); a note is kept apart in the cell's notes and never fails it */
    public const VERSION = 'moto-reference-check-v8';

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNSCORED = 'UNSCORED';

    public const READY = 'ready';

    public const NEEDS_CONFIRM = 'needs_confirm';

    public const NEEDS_CHECK = 'needs_check';

    public const FAILED = 'failed';

    private const LINE_MAX = 700;

    private const CELL_MAX = 9000;

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
            // A finding of a cell keeps the decision of its rule under the label of the cell.
            $issues = [...$issues, ...array_map(static fn (Finding|string $line): Finding|string => $line instanceof Finding ? $line->prefixed("{$cell['cell_id']}: ") : "{$cell['cell_id']}: {$line}", array_values(array_diff([...$cell['issues'], ...$cell['notes']], $envelope)))];

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
     * @return list<array{cell_id: string, fingerprint: string, edit_status: string, review_status: string, lines: list<array{section: string, text: string}>, changes: list<array<string, mixed>>, left_out: list<array{fact_ref: string, what: string, reason: string, value: string}>, gaps: list<array{missing: string, reason: string}>, issues: list<Finding|string>, notes: list<Finding>}> every answered cell of the run: failed when a finding holds it (RULE or a requirement), needs_check when the model names a gap, needs_confirm when a line was rewritten or added, ready otherwise; a note (a draft line kept that had to be rewritten, a fact left out) is kept in notes and never fails the cell
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
                    $issues[] = Finding::requirement('MotoReferenceCheck.cells.1', "left_out dẫn dữ kiện {$ref} không có trong ô.");
                } elseif (($fact['time'] ?? null) !== MotoReferenceFacts::TARGET) {
                    $issues[] = Finding::note('MotoReferenceCheck.cells.2', "bỏ dữ kiện {$ref} (\"".mb_strimwidth((string) $fact['value'], 0, 80, '…').'") — chỉ dữ kiện mục tiêu mới được bỏ; quyết định của người trái dữ kiện này thì báo gap.');
                } else {
                    $leftOut[$ref] = ['fact_ref' => $ref, 'what' => (string) ($item['what'] ?? ''), 'reason' => (string) ($item['reason'] ?? ''), 'value' => (string) $fact['value']];
                }
            }

            if ($unchanged) {
                $lines = array_map(static fn (array $line): array => ['section' => (string) $line['section'], 'text' => (string) $line['text']], array_values($draft));
                $changes = array_map(static fn (array $line): array => ['kind' => 'kept', 'section' => $line['section'], 'text' => $line['text'], 'source_line_ids' => [$line['id']], 'fact_refs' => $line['fact_refs'], 'visual' => false, 'unsupported' => []], array_values($draft));

                foreach ($gaps === [] ? $draft : [] as $lineId => $line) {
                    if ($line['must_rewrite']) {
                        $issues[] = Finding::note('MotoReferenceCheck.cells.3', "giữ nguyên dòng nháp {$lineId} — dòng này phải viết lại cho đúng thời điểm của ô.");
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
                    $issues[] = Finding::requirement('MotoReferenceCheck.cells.4', "{$label} dẫn dòng nháp {$unknown} không có trong ô.");
                }

                foreach (array_diff($refs, array_keys($facts)) as $unknown) {
                    $issues[] = Finding::requirement('MotoReferenceCheck.cells.5', "{$label} dẫn dữ kiện {$unknown} không có trong ô.");
                }

                if ($refs === []) {
                    $issues[] = Finding::note('MotoReferenceCheck.cells.6', "{$label} không dẫn dữ kiện nào.");
                }

                if (mb_strlen($text) === 0 || mb_strlen($text) > self::LINE_MAX) {
                    $issues[] = Finding::requirement('MotoReferenceCheck.cells.7', "{$label} dài ".mb_strlen($text).' ký tự; cho phép 1–'.self::LINE_MAX.'.');
                }

                if (! in_array($section, MotoReferencePlanner::SECTIONS, true)) {
                    $issues[] = Finding::requirement('MotoReferenceCheck.cells.8', "{$label} có mục ".json_encode($section, JSON_UNESCAPED_UNICODE).' không hợp lệ.');
                }

                $kept = count($sources) === 1 && isset($draft[$sources[0]]) && $draft[$sources[0]]['text'] === $text;

                if ($kept && $draft[$sources[0]]['must_rewrite']) {
                    $issues[] = Finding::note('MotoReferenceCheck.cells.9', "{$label} giữ nguyên dòng nháp {$sources[0]} — dòng này phải viết lại cho đúng thời điểm của ô.");
                }

                $issues = [...$issues, ...array_map(static fn (Finding|string $problem): Finding|string => $problem instanceof Finding ? $problem->prefixed("{$label} ") : "{$label} {$problem}", self::idIssues($text, $sent))];
                $changes[] = [
                    'kind' => $kept ? 'kept' : ($sources === [] ? 'added' : 'rewritten'),
                    'section' => $section,
                    'text' => $text,
                    'source_line_ids' => $sources,
                    'fact_refs' => $refs,
                    'visual' => ! $kept,
                    'unsupported' => [],
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
                        $issues[] = Finding::note('MotoReferenceCheck.cells.10', "bỏ mất dòng nháp {$lineId} — giữ, viết lại hoặc gộp mỗi dòng nháp vào ít nhất một dòng.");

                        continue;
                    }

                    $refs = array_merge([], ...array_column($kept, 'refs'));

                    foreach (array_diff((array) $line['fact_refs'], $refs, array_keys($leftOut)) as $lost) {
                        $issues[] = Finding::note('MotoReferenceCheck.cells.11', "dòng viết lại từ {$lineId} bỏ mất dữ kiện {$lost} (\"".mb_strimwidth((string) ($facts[$lost]['value'] ?? ''), 0, 80, '…').'") mà không ghi vào left_out.');
                    }
                }
            }

            $length = mb_strlen(implode("\n", array_column($lines, 'text')));

            if ($length > self::CELL_MAX) {
                $issues[] = Finding::requirement('MotoReferenceCheck.cells.12', "nội dung ô dài {$length} ký tự; tối đa ".self::CELL_MAX.'.');
            }

            $revised = array_filter($changes, static fn (array $change): bool => $change['kind'] !== 'kept') !== [];
            // The status of the cell follows the decision of each finding: a note is shown, never a failure. A rewritten prompt still waits for the person to confirm it.
            $noted = array_values(array_filter($issues, static fn (Finding|string $issue): bool => $issue instanceof Finding && $issue->kind === MotoCheckRules::NOTE));
            $held = array_values(array_filter($issues, static fn (Finding|string $issue): bool => ! ($issue instanceof Finding && $issue->kind === MotoCheckRules::NOTE)));
            $cells[$id] = [
                'cell_id' => $id,
                'fingerprint' => (string) $sent['fingerprint'],
                'edit_status' => $unchanged ? 'unchanged' : 'revised',
                'review_status' => match (true) {
                    $held !== [] => self::FAILED,
                    $gaps !== [] => self::NEEDS_CHECK,
                    $revised => self::NEEDS_CONFIRM,
                    default => self::READY,
                },
                'lines' => $lines,
                'changes' => $changes,
                'left_out' => array_values($leftOut),
                'gaps' => $gaps,
                'issues' => array_values(array_unique($held)),
                'notes' => array_values(array_unique($noted)),
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
            $issues[] = Finding::blocking('MotoReferenceCheck.envelopeIssues.1', 'Phản hồi không theo định dạng '.MotoContract::REFERENCE_FORMAT.'.');
        }

        if (($response['status'] ?? null) !== 'ok') {
            $issues[] = Finding::blocking('MotoReferenceCheck.envelopeIssues.2', 'Phản hồi có status '.json_encode($response['status'] ?? null, JSON_UNESCAPED_UNICODE).', không phải ok.');
        }

        if (! is_string($context['project_id'] ?? null) || ($response['project_id'] ?? null) !== $context['project_id']) {
            $issues[] = Finding::blocking('MotoReferenceCheck.envelopeIssues.3', 'project_id không khớp lượt chạy.');
        }

        if (! is_string($context['prompt_version'] ?? null) || ($response['prompt_version'] ?? null) !== $context['prompt_version']) {
            $issues[] = Finding::blocking('MotoReferenceCheck.envelopeIssues.4', 'prompt_version là '.json_encode($response['prompt_version'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'; lượt chạy này dùng '.($context['prompt_version'] ?? 'null').'.');
        }

        $answered = array_map(static fn (mixed $cell): string => is_array($cell) ? (string) ($cell['cell_id'] ?? '') : '', array_values((array) ($response['data']['cells'] ?? [])));
        $expected = array_map('strval', array_keys((array) ($context['cells'] ?? [])));
        $twice = array_keys(array_filter(array_count_values($answered), static fn (int $count): bool => $count > 1));
        $missing = array_values(array_diff($expected, $answered));
        $unknown = array_values(array_diff($answered, $expected));

        if ($twice !== []) {
            $issues[] = Finding::blocking('MotoReferenceCheck.envelopeIssues.5', 'Ô trả về hai lần: '.implode(', ', $twice).'.');
        }

        if ($missing !== []) {
            $issues[] = Finding::blocking('MotoReferenceCheck.envelopeIssues.6', 'Thiếu ô đã gửi: '.implode(', ', $missing).'.');
        }

        if ($unknown !== []) {
            $issues[] = Finding::blocking('MotoReferenceCheck.envelopeIssues.7', 'Ô không có trong lượt gửi: '.implode(', ', $unknown).'.');
        }

        if ($twice === [] && $missing === [] && $unknown === [] && $answered !== $expected) {
            $issues[] = Finding::blocking('MotoReferenceCheck.envelopeIssues.8', 'Các ô trả về không theo thứ tự đã gửi ('.implode(', ', $expected).').');
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
     * @return list<string> the ids a line holds that are not the cell's own: a part id the cell does not list, another build state, a work item that is not its checkpoint. What the line says in words — colours, materials, numbers, people, tools, placement — is content the model writes and the reviewer judges: it is not read here
     */
    private static function idIssues(string $text, array $cell): array
    {
        $issues = [];
        preg_match_all('/\bP\d{2}\b/u', $text, $parts);
        preg_match_all('/\bBS\d{2}\b/u', $text, $states);
        preg_match_all('/\bSC\d{2}-W\d+\b/u', $text, $items);

        foreach (array_unique(array_diff($parts[0], (array) $cell['parts'])) as $part) {
            $issues[] = Finding::requirement('MotoReferenceCheck.idIssues.1', "nhắc {$part}, part không thuộc ô.");
        }

        foreach (array_unique(array_diff($states[0], [(string) $cell['state']])) as $state) {
            $issues[] = Finding::requirement('MotoReferenceCheck.idIssues.2', "nhắc build state {$state}, không phải {$cell['state']} của ô.");
        }

        foreach (array_unique(array_diff($items[0], (array) $cell['checkpoint'])) as $item) {
            $issues[] = Finding::requirement('MotoReferenceCheck.idIssues.3', "nhắc công việc {$item}, không phải mốc của ô.");
        }

        return $issues;
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
            ...Finding::fields($issues, $warnings),
            'judge' => 'not_run',
        ];
    }
}
