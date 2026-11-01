<?php

declare(strict_types=1);

namespace App\Video\Inspiration;

final class SourceCheck
{
    public const FORMAT = 'phase1-evidence-v1';

    public const VERSION = 'source-check-v1';

    public const VERSION_V2 = 'source-check-v2';

    public const SOURCE_CONFLICT = 'SOURCE_CONFLICT';

    public const QUOTES_NOT_FOUND = 'QUOTES_NOT_FOUND';

    /** @var list<string> the exact issue paths whose conflict leaves the subject itself in doubt */
    private const IDENTITY_FIELDS = ['subject', 'subject.article_kind', 'subject.status', 'subject.primary', 'subject.basis', 'inspiration.source_identity.name', 'inspiration.source_identity.builder', 'inspiration.source_identity.designers'];

    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const UNVERIFIED = 'unverified';

    public const RUN = 'RUN';

    public const REJECT = 'REJECT';

    public const STOP = 'STOP';

    public const UNSCORED = 'UNSCORED';

    public const THIN_SOURCE = 'THIN_SOURCE';

    private const PASS_MARK = 5;

    private const FULL_MARK = 7;

    /**
     * @param  array<string, mixed>|null  $response
     * @param  ?list<string>  $schemaErrors  for a phase1-evidence-v2 answer: its violations of the schema the run pinned
     * @param  ?string  $contract  the format the run pinned; the answer's own format never chooses the check
     * @return array{version: string, format: string, verdict: string, score_min: int, score_max: int, flags: list<string>, criteria: list<array<string, mixed>>, error: ?string}
     */
    public static function evaluate(?array $response, string $articleBlock, ?array $schemaErrors = null, ?string $contract = null): array
    {
        if ($response === null) {
            return self::unscored('Phản hồi không đọc được JSON.');
        }

        $format = $response['format'] ?? null;
        $contract ??= $format === PhaseOneEvidence::FORMAT_V2 ? PhaseOneEvidence::FORMAT_V2 : self::FORMAT;

        if ($contract === PhaseOneEvidence::FORMAT_V2) {
            return $format === PhaseOneEvidence::FORMAT_V2
                ? self::evidence($response, $articleBlock, $schemaErrors ?? ['không có JSON Schema đã ghim để kiểm'])
                : ['format' => PhaseOneEvidence::FORMAT_V2, 'version' => self::VERSION_V2] + self::unscored('Lượt này ghim hợp đồng '.PhaseOneEvidence::FORMAT_V2.' nhưng phản hồi khai format '.json_encode($format).' — sai hợp đồng, không dùng tiếp.');
        }

        if (($response['format'] ?? null) !== self::FORMAT || ! is_array($response['inspiration'] ?? null)) {
            return self::unscored('Phản hồi không theo định dạng bằng chứng '.self::FORMAT.'.');
        }

        $inspiration = $response['inspiration'];
        $structure = self::structureErrors($inspiration);

        if ($structure !== []) {
            return self::unscored('Phản hồi thiếu hoặc sai cấu trúc: '.implode('; ', $structure).'.');
        }

        $article = self::normalize($articleBlock);
        $captions = self::captions($articleBlock);

        $quotedIn = static fn (string $haystack): \Closure => static fn (array $item): ?string => self::quoted($item['source_quote'] ?? null, $haystack) ? (string) $item['source_quote'] : null;
        $criteria = [
            self::identity($inspiration['source_identity']['identification_quote'], $article),
            self::listed('exterior', 'Mô tả ≥ 3 chi tiết thiết kế ngoại thất', 3, 3, $inspiration['exterior'], 'feature', $quotedIn($article)),
            self::listed('interior', 'Mô tả ≥ 2 không gian hoặc vật liệu nội thất', 2, 2, $inspiration['interior'], 'feature', $quotedIn($article)),
            self::intent($inspiration['intent'], $article),
            self::listed('technology', 'Mô tả công nghệ hoặc động lực', 1, 1, $inspiration['technology'], 'feature', $quotedIn($article)),
            self::scale($inspiration['scale'], $article),
            $captions === []
                ? self::criterion('visuals', 'Có ảnh hoặc chú thích mô tả diện mạo', 1, self::UNVERIFIED, [], ['Dữ liệu bài không có ảnh hoặc chú thích nào để kiểm.'])
                : self::listed('visuals', 'Có ảnh hoặc chú thích mô tả diện mạo', 1, 1, $inspiration['visuals'], 'description', $quotedIn(implode("\n", $captions))),
        ];

        return self::verdict($criteria, self::VERSION, self::FORMAT, []);
    }

    /**
     * @param  ?array<string, mixed>  $check  a result of evaluate()
     * @return bool true when the answer itself broke the contract (unreadable, wrong format, schema, structure, issue paths, quotes), so a new answer may fix it; false for a thin, ambiguous, conflicting or off-topic source, which a new answer cannot fix
     */
    public static function answerUnusable(?array $check): bool
    {
        return ($check['verdict'] ?? null) === self::UNSCORED && is_string($check['error'] ?? null);
    }

    /**
     * Ket qua phase1-evidence-v2: schema da ghim truoc; roi moi trich dan phai co nguyen van tren mot
     * dong mang dung the nguon, moi van de nguon tro dung mot truong hoac mot muc da khai; doi tuong
     * chinh mo ho hoac danh tinh mau thuan (dung truong, khong so tien to) thi chua cham duoc, bai
     * khong ve mot tau cu the thi dung; thieu truong phu khong chan.
     *
     * @param  array<string, mixed>  $answer
     * @param  list<string>  $schemaErrors
     * @return array<string, mixed>
     */
    private static function evidence(array $answer, string $articleBlock, array $schemaErrors): array
    {
        if ($schemaErrors !== []) {
            return ['format' => PhaseOneEvidence::FORMAT_V2, 'version' => self::VERSION_V2, 'schema_errors' => $schemaErrors]
                + self::unscored('Phản hồi sai JSON Schema đã ghim: '.implode('; ', array_slice($schemaErrors, 0, 5)).'.');
        }

        $lines = self::tagged($articleBlock);
        $checked = 0;
        $missing = [];
        $proven = static function (mixed $evidence, string $path) use ($lines, &$checked, &$missing): ?string {
            $quotes = [];

            foreach (is_array($evidence) ? $evidence : [] as $index => $entry) {
                $checked++;
                $quote = self::normalize((string) ($entry['quote'] ?? ''));
                $found = $quote !== '' && array_filter($lines[(string) ($entry['source'] ?? '')] ?? [], static fn (string $line): bool => str_contains($line, $quote)) !== [];

                if (! $found) {
                    $missing[] = "{$path}.evidence[{$index}]: ".(string) ($entry['quote'] ?? '');
                }

                $quotes[] = $found ? (string) $entry['quote'] : null;
            }

            return $quotes !== [] && ! in_array(null, $quotes, true) ? implode(' | ', $quotes) : null;
        };

        $data = $answer['inspiration'];
        $subject = $answer['subject'];
        $identityProof = $proven($subject['basis'], 'subject.basis');

        foreach ((array) $subject['others'] as $index => $other) {
            $proven($other['evidence'], "subject.others[{$index}]");
        }

        $paths = ['subject' => true, 'inspiration.intent' => true];

        foreach (array_keys((array) $subject) as $field) {
            $paths["subject.{$field}"] = true;
        }

        foreach (array_keys((array) $data['scale']) as $field) {
            $paths["inspiration.scale.{$field}"] = true;
        }

        foreach ($data['source_identity'] as $field => $value) {
            $paths["inspiration.source_identity.{$field}"] = true;

            if ($field !== 'signatures' && is_array($value)) {
                $proven($value['evidence'], "source_identity.{$field}");
            }
        }

        $structure = [];
        $proofs = [];
        $lists = ['exterior' => 'feature', 'interior' => 'feature', 'technology' => 'feature', 'visuals' => 'description', 'claims' => 'claim'];

        foreach ([...$lists, 'signatures' => 'feature'] as $list => $field) {
            $ids = [];
            $texts = [];
            $base = $list === 'signatures' ? 'inspiration.source_identity.signatures' : "inspiration.{$list}";
            $paths[$base] = true;

            foreach ((array) ($list === 'signatures' ? $data['source_identity']['signatures'] : $data[$list]) as $index => $item) {
                $id = (string) ($item['id'] ?? '');
                $text = mb_strtolower(self::normalize((string) ($item[$field] ?? '')));
                $proofs[$list.'#'.$id] = $proven($item['evidence'] ?? null, "{$list}[{$index}]");
                $paths["{$base}.{$id}"] = true;

                if ($text === '') {
                    $structure[] = "{$list}[{$index}] ({$id}): nội dung trống.";
                } elseif (isset($texts[$text])) {
                    $structure[] = "{$list}[{$index}] ({$id}): trùng nội dung với {$texts[$text]}.";
                }

                if (isset($ids[$id])) {
                    $structure[] = "{$list}[{$index}]: ID {$id} lặp.";
                }

                $ids[$id] = true;
                $texts[$text] ??= $id;
            }
        }

        $issues = (array) $answer['issues'];
        $issuePaths = [];

        foreach ($issues as $index => $issue) {
            if (! isset($paths[(string) $issue['field']])) {
                $issuePaths[] = "issues[{$index}]: \"{$issue['field']}\" không phải đường dẫn đầy đủ của câu trả lời này.";
            }

            if ($issue['evidence'] !== []) {
                $proven($issue['evidence'], "issues[{$index}]");
            }
        }

        $conflicts = array_values(array_filter($issues, static fn (array $issue): bool => $issue['kind'] === 'conflict'));
        $identityConflicts = array_values(array_filter($conflicts, static fn (array $issue): bool => in_array($issue['field'], self::IDENTITY_FIELDS, true)));
        $label = 'Nói về một siêu du thuyền hoặc thiết kế siêu du thuyền cụ thể';
        $identity = match (true) {
            $subject['status'] === 'none' || $subject['article_kind'] === 'not_a_yacht' => self::criterion('identity', $label, 0, self::FAIL, [], []),
            $subject['status'] === 'ambiguous' => self::criterion('identity', $label, 0, self::UNVERIFIED, [], ['Bài nhắc nhiều tàu nhưng không xác định được đối tượng chính — không gộp thành một hồ sơ.']),
            $identityConflicts !== [] => self::criterion('identity', $label, 0, self::UNVERIFIED, [], array_map(static fn (array $issue): string => "Mâu thuẫn {$issue['field']}: {$issue['message']}", $identityConflicts)),
            $identityProof === null => self::criterion('identity', $label, 0, self::UNVERIFIED, [], ['Trích dẫn xác định đối tượng không có nguyên văn trong bài.']),
            default => self::criterion('identity', $label, 0, self::PASS, ["{$subject['primary']}: {$identityProof}"], []),
        };
        $byItem = static fn (string $list): \Closure => static fn (array $item): ?string => $proofs[$list.'#'.(string) ($item['id'] ?? '')] ?? null;
        $captions = $lines['caption'] ?? [];
        $intent = $data['intent'];
        $intentProof = is_array($intent) ? $proven($intent['evidence'], 'intent') : null;
        $scaleEvidence = [];
        $scaleIssues = [];

        foreach ($data['scale'] as $field => $measure) {
            if (! is_array($measure)) {
                continue;
            }

            $proof = $proven($measure['evidence'], "scale.{$field}");

            if (! in_array($field, ['loa', 'decks', 'layout'], true)) {
                continue;
            }

            if ($proof !== null) {
                $scaleEvidence[] = "{$field}: {$measure['value']} — {$proof}";
            } else {
                $scaleIssues[] = "{$field}: trích dẫn không có nguyên văn trên dòng nguồn đã khai.";
            }
        }

        $criteria = [
            $identity,
            self::listed('exterior', 'Mô tả ≥ 3 chi tiết thiết kế ngoại thất', 3, 3, $data['exterior'], 'feature', $byItem('exterior')),
            self::listed('interior', 'Mô tả ≥ 2 không gian hoặc vật liệu nội thất', 2, 2, $data['interior'], 'feature', $byItem('interior')),
            match (true) {
                ! is_array($intent) => self::criterion('intent', 'Nêu ý đồ, yêu cầu hoặc concept thiết kế', 2, self::FAIL, [], []),
                $intentProof !== null => self::criterion('intent', 'Nêu ý đồ, yêu cầu hoặc concept thiết kế', 2, self::PASS, ["{$intent['text']}: {$intentProof}"], []),
                default => self::criterion('intent', 'Nêu ý đồ, yêu cầu hoặc concept thiết kế', 2, self::UNVERIFIED, [], ['Trích dẫn không có nguyên văn trên dòng nguồn đã khai.']),
            },
            self::listed('technology', 'Mô tả công nghệ hoặc động lực', 1, 1, $data['technology'], 'feature', $byItem('technology')),
            self::criterion('scale', 'Mô tả kích thước, số boong hoặc bố cục', 1, $scaleEvidence !== [] ? self::PASS : ($scaleIssues !== [] ? self::UNVERIFIED : self::FAIL), $scaleEvidence, $scaleIssues),
            $captions === []
                ? self::criterion('visuals', 'Có ảnh hoặc chú thích mô tả diện mạo', 1, self::UNVERIFIED, [], ['Dữ liệu bài không có ảnh hoặc chú thích nào để kiểm.'])
                : self::listed('visuals', 'Có ảnh hoặc chú thích mô tả diện mạo', 1, 1, $data['visuals'], 'description', $byItem('visuals')),
        ];
        $flags = array_values(array_filter([
            count($conflicts) > count($identityConflicts) ? self::SOURCE_CONFLICT : null,
            $missing !== [] ? self::QUOTES_NOT_FOUND : null,
        ]));
        $result = self::verdict($criteria, self::VERSION_V2, PhaseOneEvidence::FORMAT_V2, $flags);
        $blocking = array_values(array_filter([
            $structure === [] ? null : 'Mục khai trùng hoặc trống — mỗi mục một ID và một nội dung: '.implode('; ', array_slice($structure, 0, 5)),
            $issuePaths === [] ? null : 'Vấn đề nguồn trỏ tới trường không có trong câu trả lời — không xác định được mâu thuẫn thuộc trường nào: '.implode('; ', array_slice($issuePaths, 0, 5)),
            $missing === [] ? null : count($missing).' trích dẫn không có nguyên văn trên dòng nguồn đã khai — giá trị đã khai mà không có bằng chứng thì kết quả chưa dùng tiếp được: '.implode('; ', array_slice($missing, 0, 5)),
        ]));

        if ($blocking !== []) {
            $result = ['verdict' => self::UNSCORED, 'flags' => $flags, 'error' => implode(' ', $blocking)] + $result;
        }

        return $result + [
            'schema_errors' => [],
            'structure' => $structure,
            'issue_paths' => $issuePaths,
            'evidence' => ['checked' => $checked, 'missing' => $missing],
            'subject' => ['article_kind' => $subject['article_kind'], 'status' => $subject['status'], 'primary' => $subject['primary']],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $criteria
     * @param  list<string>  $flags
     * @return array{version: string, format: string, verdict: string, score_min: int, score_max: int, flags: list<string>, criteria: list<array<string, mixed>>, error: ?string}
     */
    private static function verdict(array $criteria, string $version, string $format, array $flags): array
    {
        $min = 0;
        $max = 0;

        foreach ($criteria as $criterion) {
            $min += $criterion['status'] === self::PASS ? $criterion['points'] : 0;
            $max += $criterion['status'] === self::FAIL ? 0 : $criterion['points'];
        }

        $verdict = match (true) {
            $criteria[0]['status'] === self::FAIL => self::STOP,
            $criteria[0]['status'] === self::UNVERIFIED => self::UNSCORED,
            $min >= self::PASS_MARK => self::RUN,
            $max < self::PASS_MARK => self::REJECT,
            default => self::UNSCORED,
        };

        return [
            'version' => $version,
            'format' => $format,
            'verdict' => $verdict,
            'score_min' => $min,
            'score_max' => $max,
            'flags' => [...($verdict === self::RUN && $min < self::FULL_MARK ? [self::THIN_SOURCE] : []), ...$flags],
            'criteria' => $criteria,
            'error' => null,
        ];
    }

    /**
     * @param  list<string>  $evidence
     * @param  list<string>  $issues
     * @return array{key: string, label: string, points: int, status: string, evidence: list<string>, issues: list<string>}
     */
    private static function criterion(string $key, string $label, int $points, string $status, array $evidence, array $issues): array
    {
        return ['key' => $key, 'label' => $label, 'points' => $points, 'status' => $status, 'evidence' => $evidence, 'issues' => $issues];
    }

    /**
     * @param  array<mixed>  $inspiration
     * @return list<string>
     */
    private static function structureErrors(array $inspiration): array
    {
        $errors = [];
        $identity = $inspiration['source_identity'] ?? null;

        if (! is_array($identity) || array_is_list($identity)) {
            $errors[] = 'source_identity không phải object';
        } elseif (! array_key_exists('identification_quote', $identity)) {
            $errors[] = 'thiếu source_identity.identification_quote';
        } elseif ($identity['identification_quote'] !== null && ! is_string($identity['identification_quote'])) {
            $errors[] = 'source_identity.identification_quote phải là chuỗi hoặc null';
        }

        foreach (['exterior', 'interior', 'technology', 'visuals'] as $key) {
            if (! array_key_exists($key, $inspiration)) {
                $errors[] = "thiếu {$key}";
            } elseif (! is_array($inspiration[$key]) || ! array_is_list($inspiration[$key])) {
                $errors[] = "{$key} phải là danh sách";
            }
        }

        foreach (['intent', 'scale'] as $key) {
            if (! array_key_exists($key, $inspiration)) {
                $errors[] = "thiếu {$key}";
            } elseif ($inspiration[$key] !== null && (! is_array($inspiration[$key]) || array_is_list($inspiration[$key]))) {
                $errors[] = "{$key} phải là object hoặc null";
            }
        }

        if (is_array($inspiration['intent'] ?? null)) {
            $errors = array_merge($errors, self::missingKeys($inspiration['intent'], 'intent', ['text', 'source_quote']));
        }

        if (is_array($inspiration['scale'] ?? null)) {
            foreach (['loa', 'decks', 'layout'] as $field) {
                if (! array_key_exists($field, $inspiration['scale'])) {
                    $errors[] = "thiếu scale.{$field}";
                } elseif ($inspiration['scale'][$field] !== null && (! is_array($inspiration['scale'][$field]) || array_is_list($inspiration['scale'][$field]))) {
                    $errors[] = "scale.{$field} phải là object hoặc null";
                } elseif (is_array($inspiration['scale'][$field])) {
                    $errors = array_merge($errors, self::missingKeys($inspiration['scale'][$field], "scale.{$field}", ['value', 'source_quote']));
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<mixed>  $object
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function missingKeys(array $object, string $path, array $keys): array
    {
        $errors = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $object)) {
                $errors[] = "thiếu {$path}.{$key}";
            }
        }

        return $errors;
    }

    /** @return array<string, mixed> */
    private static function identity(?string $quote, string $article): array
    {
        $label = 'Nói về một siêu du thuyền hoặc thiết kế siêu du thuyền cụ thể';

        if ($quote === null) {
            return self::criterion('identity', $label, 0, self::FAIL, [], []);
        }

        return self::quoted($quote, $article)
            ? self::criterion('identity', $label, 0, self::PASS, [$quote], [])
            : self::criterion('identity', $label, 0, self::UNVERIFIED, [], ['Trích dẫn không có nguyên văn trong bài.']);
    }

    /** @param array<string, mixed>|null $intent */
    private static function intent(?array $intent, string $article): array
    {
        $label = 'Nêu ý đồ, yêu cầu hoặc concept thiết kế';

        if ($intent === null) {
            return self::criterion('intent', $label, 2, self::FAIL, [], []);
        }

        $text = $intent['text'];
        $quote = $intent['source_quote'];
        $issues = [];

        if (! is_string($text) || trim($text) === '') {
            $issues[] = 'Thiếu nội dung ý đồ (text).';
        }

        if (! self::quoted($quote, $article)) {
            $issues[] = 'Trích dẫn không có nguyên văn trong bài.';
        }

        return $issues === []
            ? self::criterion('intent', $label, 2, self::PASS, ["{$text}: {$quote}"], [])
            : self::criterion('intent', $label, 2, self::UNVERIFIED, [], $issues);
    }

    /** @param array<string, mixed>|null $scale */
    private static function scale(?array $scale, string $article): array
    {
        $label = 'Mô tả kích thước, số boong hoặc bố cục';

        if ($scale === null) {
            return self::criterion('scale', $label, 1, self::FAIL, [], []);
        }

        $evidence = [];
        $issues = [];

        foreach (['loa', 'decks', 'layout'] as $field) {
            $entry = $scale[$field] ?? null;

            if ($entry === null) {
                continue;
            }

            $value = is_array($entry) ? ($entry['value'] ?? null) : null;
            $quote = is_array($entry) ? ($entry['source_quote'] ?? null) : null;

            if (is_scalar($value) && trim((string) $value) !== '' && self::quoted($quote, $article)) {
                $evidence[] = "{$field}: {$quote}";
            } else {
                $issues[] = "{$field}: thiếu giá trị hoặc trích dẫn không có nguyên văn trong bài.";
            }
        }

        $status = $evidence !== [] ? self::PASS : ($issues !== [] ? self::UNVERIFIED : self::FAIL);

        return self::criterion('scale', $label, 1, $status, $evidence, $issues);
    }

    /**
     * @param  list<mixed>  $items
     * @param  \Closure(array<string, mixed>): ?string  $proven  the quote that proves an item in the article, or null
     * @return array<string, mixed>
     */
    private static function listed(string $key, string $label, int $points, int $needed, array $items, string $field, \Closure $proven): array
    {
        if ($items === []) {
            return self::criterion($key, $label, $points, self::FAIL, [], []);
        }

        $evidence = [];
        $issues = [];
        $invalid = 0;
        $seenIds = [];
        $seenTexts = [];

        foreach ($items as $index => $item) {
            $id = is_array($item) && is_scalar($item['id'] ?? null) ? trim((string) $item['id']) : '';
            $name = $id !== '' ? $id : '#'.($index + 1);
            $text = is_array($item) && is_string($item[$field] ?? null) ? self::normalize($item[$field]) : '';

            if ($text === '') {
                $invalid++;
                $issues[] = "{$name}: thiếu nội dung.";

                continue;
            }

            if (($id !== '' && isset($seenIds[$id])) || isset($seenTexts[mb_strtolower($text)])) {
                $issues[] = "{$name}: trùng ID hoặc trùng nội dung với mục trước, không tính lại.";

                continue;
            }

            $seenTexts[mb_strtolower($text)] = true;

            if ($id !== '') {
                $seenIds[$id] = true;
            }

            $quote = $proven($item);

            if ($quote === null) {
                $invalid++;
                $issues[] = "{$name}: trích dẫn không có nguyên văn trong ".($key === 'visuals' ? 'chú thích ảnh' : 'bài').'.';

                continue;
            }

            $evidence[] = "{$name}: {$quote}";
        }

        $valid = count($evidence);
        $status = $valid >= $needed ? self::PASS : ($valid + $invalid >= $needed ? self::UNVERIFIED : self::FAIL);

        return self::criterion($key, $label, $points, $status, $evidence, $issues);
    }

    private static function quoted(mixed $quote, string $haystack): bool
    {
        if (! is_string($quote)) {
            return false;
        }

        $needle = self::normalize($quote);

        return $needle !== '' && str_contains($haystack, $needle);
    }

    /** @return list<string> */
    private static function captions(string $articleBlock): array
    {
        $captions = [];

        foreach (preg_split('/\R/u', $articleBlock) ?: [] as $line) {
            if (str_starts_with($line, '[CAPTION] ')) {
                $captions[] = self::normalize(substr($line, strlen('[CAPTION] ')));
            }
        }

        return $captions;
    }

    /** @return array<string, list<string>> source tag in lower case => the normalised text of each of its lines */
    private static function tagged(string $articleBlock): array
    {
        $lines = [];

        foreach (preg_split('/\R/u', $articleBlock) ?: [] as $line) {
            if (preg_match('/^\[([A-Z]+)\] (.*)$/su', $line, $m) === 1) {
                $lines[strtolower($m[1])][] = self::normalize($m[2]);
            }
        }

        return $lines;
    }

    private static function normalize(string $text): string
    {
        $text = str_replace(['‘', '’', '‚', '‛'], "'", $text);
        $text = str_replace(['“', '”', '„', '‟'], '"', $text);

        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    /** @return array{version: string, format: string, verdict: string, score_min: int, score_max: int, flags: list<string>, criteria: list<array<string, mixed>>, error: ?string} */
    private static function unscored(string $error): array
    {
        return [
            'version' => self::VERSION,
            'format' => self::FORMAT,
            'verdict' => self::UNSCORED,
            'score_min' => 0,
            'score_max' => 0,
            'flags' => [],
            'criteria' => [],
            'error' => $error,
        ];
    }
}
