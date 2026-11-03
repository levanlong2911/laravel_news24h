<?php

declare(strict_types=1);

namespace App\Video\Inspiration;

use App\Video\Moto\MotoContract;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Uri;
use Opis\JsonSchema\Validator;

/**
 * Doc ket qua Phase 1 cua moi hop dong cho cac buoc sau (Phase 2-4, bo kiem nguon, giao dien).
 *
 * phase1-evidence-v1 (va cau tra loi cu khong khai format) tra nguyen khoi inspiration, de payload
 * cua cac chuoi da ghim giu dung tung byte. phase1-evidence-v2 doi cau truc sang cung dang do (cac
 * khoa cu giu nguyen nghia) va giu ngu canh nguon: applies_to (ca signature), moi trich dan
 * (source_quotes), doi tuong chinh (subject) va van de nguon (issues). Khong viet lai noi dung,
 * khong dua self_check vao.
 */
final class PhaseOneEvidence
{
    public const FORMAT_V1 = 'phase1-evidence-v1';

    public const FORMAT_V2 = 'phase1-evidence-v2';

    public const INPUT_FORMAT = 'phase1-article-v1';

    /** @var list<string> the self-checks of a v2 answer, in their fixed order */
    public const CHECKS = ['subject', 'evidence', 'completeness', 'no_inference', 'no_duplicates', 'measurements', 'captions', 'contract'];

    /** @var array<string, string> list => its text field */
    private const LISTS = ['exterior' => 'feature', 'interior' => 'feature', 'technology' => 'feature'];

    /** @var list<string> */
    private const SCALE = ['loa', 'lwl', 'beam', 'draft', 'decks', 'layout', 'guests', 'crew', 'gross_tonnage', 'interior_volume'];

    /** @var list<string> identity fields after name, in the order every later step reads them */
    private const IDENTITY = ['builder', 'designers', 'silhouette', 'bow', 'superstructure', 'colors'];

    private const MAX_ERRORS = 100;

    /** @param  ?array<string, mixed>  $answer */
    public static function format(?array $answer): ?string
    {
        return is_array($answer) && is_string($answer['format'] ?? null) ? $answer['format'] : null;
    }

    /**
     * @param  ?array<string, mixed>  $answer  a decoded Phase 1 answer
     * @return ?array<string, mixed> the inspiration block every later step reads, in the shape of v1
     */
    public static function inspiration(?array $answer): ?array
    {
        $block = is_array($answer) ? ($answer['inspiration'] ?? null) : null;

        if (! is_array($block) || array_is_list($block)) {
            return null;
        }

        return match (self::format($answer)) {
            self::FORMAT_V2 => self::flatten($block, is_array($answer['subject'] ?? null) ? $answer['subject'] : [], is_array($answer['issues'] ?? null) ? $answer['issues'] : []),
            MotoContract::EVIDENCE_FORMAT => ['subject' => $answer['subject'] ?? null, ...$block, 'issues' => $answer['issues'] ?? []],
            default => $block,
        };
    }

    /**
     * @param  object  $answer  the answer decoded as objects, so empty lists and objects stay apart
     * @param  list<array{json_schema_id: string, content: string}>  $schemas  the pinned output schemas, the answer's own schema first
     * @return list<string> every violation by field path; empty when the answer matches
     */
    public static function schemaErrors(object $answer, array $schemas): array
    {
        if ($schemas === []) {
            return ['không có JSON Schema đã ghim để kiểm'];
        }

        $validator = new Validator;
        $validator->setMaxErrors(self::MAX_ERRORS);

        foreach ($schemas as $schema) {
            $validator->resolver()->registerRaw($schema['content'], $schema['json_schema_id']);
        }

        $result = $validator->validate($answer, Uri::parse($schemas[0]['json_schema_id'], true));

        if ($result->isValid()) {
            return [];
        }

        $errors = [];

        foreach ((new ErrorFormatter)->format($result->error(), true) as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = ($pointer === '' ? '(gốc)' : (string) $pointer).': '.$message;
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $block  the inspiration block of a v2 answer
     * @param  array<string, mixed>  $subject  the subject block of that answer
     * @param  list<mixed>  $issues  the source issues of that answer
     * @return array<string, mixed>
     */
    private static function flatten(array $block, array $subject, array $issues): array
    {
        $identity = is_array($block['source_identity'] ?? null) ? $block['source_identity'] : [];
        $flat = [
            'name' => self::value($identity['name'] ?? null),
            'identification_quote' => self::quote($subject['basis'] ?? null),
        ];

        foreach (self::IDENTITY as $field) {
            $flat[$field] = self::value($identity[$field] ?? null);
        }

        $flat['signatures'] = self::items($identity['signatures'] ?? null, 'feature');
        $out = ['source_identity' => $flat];

        foreach (self::LISTS as $list => $field) {
            $out[$list] = self::items($block[$list] ?? null, $field);
        }

        $out['scale'] = [];

        foreach (self::SCALE as $field) {
            $measure = $block['scale'][$field] ?? null;
            $out['scale'][$field] = is_array($measure)
                ? ['value' => $measure['value'] ?? null, 'source_quote' => self::quote($measure['evidence'] ?? null), 'applies_to' => $measure['applies_to'] ?? null, 'source_quotes' => self::quotes($measure['evidence'] ?? null)]
                : null;
        }

        $intent = $block['intent'] ?? null;
        $out['intent'] = is_array($intent) ? ['text' => $intent['text'] ?? null, 'source_quote' => self::quote($intent['evidence'] ?? null), 'source_quotes' => self::quotes($intent['evidence'] ?? null)] : null;
        $out['visuals'] = self::items($block['visuals'] ?? null, 'description');
        $out['trends'] = array_values(array_map(static fn (array $claim): mixed => $claim['claim'] ?? null, array_filter((array) ($block['claims'] ?? []), 'is_array')));
        $out['subject'] = [
            'article_kind' => $subject['article_kind'] ?? null,
            'status' => $subject['status'] ?? null,
            'primary' => $subject['primary'] ?? null,
            'others' => array_values(array_map(static fn (array $other): array => ['name' => $other['name'] ?? null, 'relation' => $other['relation'] ?? null], array_filter((array) ($subject['others'] ?? []), 'is_array'))),
        ];
        $out['issues'] = array_values(array_map(
            static fn (array $issue): array => ['kind' => $issue['kind'] ?? null, 'field' => $issue['field'] ?? null, 'message' => $issue['message'] ?? null, 'source_quotes' => self::quotes($issue['evidence'] ?? null)],
            array_filter($issues, 'is_array'),
        ));

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function items(mixed $list, string $field): array
    {
        return array_values(array_map(
            static fn (array $item): array => array_filter([
                'id' => $item['id'] ?? null,
                $field => $item[$field] ?? null,
                'source_quote' => self::quote($item['evidence'] ?? null),
                'applies_to' => $item['applies_to'] ?? false,
                'source_quotes' => self::quotes($item['evidence'] ?? null),
            ], static fn (mixed $value): bool => $value !== false),
            array_filter(is_array($list) ? $list : [], 'is_array'),
        ));
    }

    /** @return list<string> every quote of an evidence list, in order */
    private static function quotes(mixed $evidence): array
    {
        return array_values(array_filter(array_map(static fn (mixed $entry): mixed => is_array($entry) ? ($entry['quote'] ?? null) : null, is_array($evidence) ? $evidence : []), 'is_string'));
    }

    private static function value(mixed $field): mixed
    {
        return is_array($field) ? ($field['value'] ?? null) : null;
    }

    private static function quote(mixed $evidence): ?string
    {
        $first = is_array($evidence) ? ($evidence[0] ?? null) : null;

        return is_array($first) && is_string($first['quote'] ?? null) ? $first['quote'] : null;
    }
}
