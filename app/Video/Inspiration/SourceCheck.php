<?php

declare(strict_types=1);

namespace App\Video\Inspiration;

final class SourceCheck
{
    public const FORMAT = 'phase1-evidence-v1';

    public const VERSION = 'source-check-v1';

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
     * @return array{version: string, format: string, verdict: string, score_min: int, score_max: int, flags: list<string>, criteria: list<array<string, mixed>>, error: ?string}
     */
    public static function evaluate(?array $response, string $articleBlock): array
    {
        if ($response === null) {
            return self::unscored('Phản hồi không đọc được JSON.');
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

        $criteria = [
            self::identity($inspiration['source_identity']['identification_quote'], $article),
            self::listed('exterior', 'Mô tả ≥ 3 chi tiết thiết kế ngoại thất', 3, 3, $inspiration['exterior'], 'feature', $article),
            self::listed('interior', 'Mô tả ≥ 2 không gian hoặc vật liệu nội thất', 2, 2, $inspiration['interior'], 'feature', $article),
            self::intent($inspiration['intent'], $article),
            self::listed('technology', 'Mô tả công nghệ hoặc động lực', 1, 1, $inspiration['technology'], 'feature', $article),
            self::scale($inspiration['scale'], $article),
            $captions === []
                ? self::criterion('visuals', 'Có ảnh hoặc chú thích mô tả diện mạo', 1, self::UNVERIFIED, [], ['Dữ liệu bài không có ảnh hoặc chú thích nào để kiểm.'])
                : self::listed('visuals', 'Có ảnh hoặc chú thích mô tả diện mạo', 1, 1, $inspiration['visuals'], 'description', implode("\n", $captions)),
        ];

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
            'version' => self::VERSION,
            'format' => self::FORMAT,
            'verdict' => $verdict,
            'score_min' => $min,
            'score_max' => $max,
            'flags' => $verdict === self::RUN && $min < self::FULL_MARK ? [self::THIN_SOURCE] : [],
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
     * @return array<string, mixed>
     */
    private static function listed(string $key, string $label, int $points, int $needed, array $items, string $field, string $haystack): array
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
            $quote = is_array($item) ? ($item['source_quote'] ?? null) : null;

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

            if (! self::quoted($quote, $haystack)) {
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
