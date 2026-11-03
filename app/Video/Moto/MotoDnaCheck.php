<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Video\DesignDna\DnaCheck;

final class MotoDnaCheck
{
    public const VERSION = 'moto-dna-check-v2';

    /** @var list<string> the self-checks of a moto-dna-v1 answer, in their fixed order */
    public const CHECKS = ['source_floor', 'brief', 'theme', 'departures', 'features', 'plausibility', 'coherence', 'silhouette', 'must_avoid', 'history', 'hero', 'contract_and_names'];

    /** @var list<string> the source lists whose ids a departure may cite */
    private const SOURCE_LISTS = ['styling', 'chassis', 'powertrain', 'technology', 'visuals'];

    /** @var list<string> relations whose names are model names of the base, not names of another bike */
    private const BASE_RELATIONS = ['donor', 'base_model'];

    private const NOVELTY_CLAIM = '/world[- ]first|never seen before|first[- ]ever|the first (?:motorcycle|bike|custom|of its kind)/iu';

    /** @var array<string, list<string>> brief group => the fields the user may fix */
    private const BRIEF_FIELDS = [
        'platform' => ['family', 'model', 'era', 'engine_family'],
        'rider' => ['height_cm', 'build', 'needs'],
        'purpose' => ['kind', 'text'],
    ];

    /**
     * @param  ?array<string, mixed>  $answer  the decoded answer
     * @param  array<string, mixed>  $inspiration  the Phase 1 block the run sent
     * @param  list<string>  $flags  the source flags the run sent
     * @param  array<string, mixed>  $brief  the brief the run sent; a null value was left to the model
     * @param  list<array<string, mixed>>  $history  the concept history the run sent
     * @param  list<string>  $schemaErrors  the answer's violations of the pinned schema
     * @return array{version: string, format: string, verdict: string, issues: list<string>, schema_errors: list<string>, error: ?string}
     */
    public static function evaluate(?array $answer, array $inspiration, array $flags, array $brief, array $history, array $schemaErrors): array
    {
        if ($answer === null) {
            return self::unscored('Phản hồi không đọc được JSON.');
        }

        if (($answer['format'] ?? null) !== MotoContract::DNA_FORMAT) {
            return self::unscored('Lượt này ghim hợp đồng '.MotoContract::DNA_FORMAT.' nhưng phản hồi khai format '.json_encode($answer['format'] ?? null).' — sai hợp đồng, không dùng tiếp.');
        }

        if ($schemaErrors !== []) {
            return ['schema_errors' => $schemaErrors] + self::unscored('Phản hồi sai JSON Schema đã ghim: '.implode('; ', array_slice($schemaErrors, 0, 5)).'.');
        }

        $dna = $answer['dna'];
        $issues = [];
        $sourceIds = self::departureIds($inspiration);

        if (in_array(DnaCheck::THIN_SOURCE, $flags, true) && count($dna['departures']) !== 5) {
            $issues[] = 'Nguồn mỏng (THIN_SOURCE) cần đúng 5 departure, đang có '.count($dna['departures']).'.';
        }

        foreach ($dna['departures'] as $departure) {
            if (! in_array($departure['from_id'], $sourceIds, true)) {
                $issues[] = "{$departure['id']}: from_id \"{$departure['from_id']}\" không phải ID signature, styling, chassis, powertrain, technology hay visual nào của nguồn.";
            }
        }

        foreach (['departures' => 'D', 'features' => 'F'] as $list => $prefix) {
            foreach (array_column($dna[$list], 'id') as $index => $id) {
                if ($id !== $prefix.($index + 1)) {
                    $issues[] = "{$list}[{$index}] có ID {$id}; ID phải đánh số liên tiếp từ {$prefix}1.";
                }
            }
        }

        $engine = mb_strtolower(trim((string) $dna['brief']['platform']['engine_family']));

        if (array_filter($dna['brief']['keep'], static fn (string $item): bool => str_contains(mb_strtolower($item), $engine)) === []) {
            $issues[] = "brief.keep: không mục nào giữ dòng máy \"{$dna['brief']['platform']['engine_family']}\".";
        }

        $distinct = array_unique(array_map(static fn (string $item): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $item))), $dna['must_avoid']));

        if (count($distinct) < 8) {
            $issues[] = 'must_avoid cần ít nhất 8 mục khác nhau, đang có '.count($distinct).'.';
        }

        $texts = self::creativeText($dna);

        foreach (self::blockedNames($inspiration) as $blocked) {
            foreach ($texts as $path => $text) {
                if (MotoContract::mentions($text, $blocked)) {
                    $issues[] = "{$path}: dùng tên nguồn \"{$blocked}\" trong nội dung sáng tạo.";
                }
            }
        }

        foreach ($texts as $path => $text) {
            if (preg_match(self::NOVELTY_CLAIM, $text, $match) === 1) {
                $issues[] = "{$path}: tự nhận mới lạ \"{$match[0]}\" — chỉ được gọi là original concept design.";
            }
        }

        $issues = [...$issues, ...self::briefIssues($dna['brief'], $brief), ...self::historyIssues($dna, $texts, $history)];

        return [
            'version' => self::VERSION,
            'format' => MotoContract::DNA_FORMAT,
            'verdict' => $issues === [] ? DnaCheck::PASS : DnaCheck::FAIL,
            'issues' => $issues,
            'schema_errors' => [],
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $inspiration  a moto Phase 1 block
     * @return list<string> every id a departure may cite
     */
    public static function departureIds(array $inspiration): array
    {
        $ids = [];
        $lists = [...array_map(static fn (string $list): mixed => $inspiration[$list] ?? null, self::SOURCE_LISTS), $inspiration['source_identity']['signatures'] ?? null];

        foreach ($lists as $items) {
            foreach (is_array($items) ? $items : [] as $item) {
                if (is_array($item) && is_string($item['id'] ?? null) && trim($item['id']) !== '') {
                    $ids[] = trim($item['id']);
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $written  the brief of the answer
     * @param  array<string, mixed>  $given  the brief the run sent
     * @return list<string>
     */
    private static function briefIssues(array $written, array $given): array
    {
        $issues = [];

        foreach (self::BRIEF_FIELDS as $group => $fields) {
            $fixed = is_array($given[$group] ?? null) ? array_filter(array_intersect_key($given[$group], array_flip($fields)), static fn (mixed $value): bool => $value !== null) : [];

            foreach ($fixed as $field => $value) {
                if (($written[$group][$field] ?? null) !== $value) {
                    $issues[] = "brief.{$group}.{$field}: phải chép đúng giá trị đã cho ".json_encode($value, JSON_UNESCAPED_UNICODE).'.';
                }
            }

            $basis = $fixed === [] ? 'decided' : 'provided';

            if (($written[$group]['basis'] ?? null) !== $basis) {
                $issues[] = "brief.{$group}.basis: phải là {$basis}.";
            }
        }

        foreach (['keep', 'change_scope'] as $list) {
            foreach (is_array($given[$list] ?? null) ? $given[$list] : [] as $value) {
                if (! in_array($value, $written[$list], true)) {
                    $issues[] = "brief.{$list}: thiếu mục đã cho ".json_encode($value, JSON_UNESCAPED_UNICODE).'.';
                }
            }
        }

        $limits = is_string($given['limits'] ?? null) ? $given['limits'] : null;

        if ($written['limits'] !== ['text' => $limits, 'basis' => $limits === null ? 'none' : 'provided']) {
            $issues[] = $limits === null ? 'brief.limits: chưa có giới hạn nào được cho — phải là text null, basis none.' : 'brief.limits: phải chép đúng giới hạn đã cho, basis provided.';
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $dna
     * @param  array<string, string>  $texts  the creative texts of the DNA by path
     * @param  list<array<string, mixed>>  $history  the concept history the run sent
     * @return list<string> every theme, feature name or motif of an earlier concept the DNA repeats word for word
     */
    private static function historyIssues(array $dna, array $texts, array $history): array
    {
        $issues = [];
        $same = static fn (string $a, string $b): bool => mb_strtolower(trim($a)) === mb_strtolower(trim($b));

        foreach ($history as $entry) {
            $id = (string) ($entry['id'] ?? '?');

            if ($same((string) ($entry['theme'] ?? ''), $dna['theme'])) {
                $issues[] = "theme lặp concept {$id}.";
            }

            $names = array_map(static fn (mixed $feature): string => trim((string) strtok((string) $feature, ':')), (array) ($entry['features'] ?? []));

            foreach ($dna['features'] as $feature) {
                foreach ($names as $name) {
                    if ($name !== '' && $same($name, $feature['name'])) {
                        $issues[] = "{$feature['id']} \"{$feature['name']}\" lặp signature feature của concept {$id}.";
                    }
                }
            }

            foreach ((array) ($entry['motifs'] ?? []) as $motif) {
                foreach ($texts as $path => $text) {
                    if (is_string($motif) && trim($motif) !== '' && ! str_starts_with($path, 'must_avoid') && preg_match('/(?<![\p{L}\p{N}])'.preg_quote(trim($motif), '/').'(?![\p{L}\p{N}])/iu', $text) === 1) {
                        $issues[] = "{$path}: đưa lại motif \"{$motif}\" của concept {$id}.";
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $dna
     * @return array<string, string> where each text sits => the text
     */
    private static function creativeText(array $dna): array
    {
        $texts = ['theme' => $dna['theme'], 'silhouette' => $dna['silhouette'], 'hero_moment' => $dna['hero_moment']];

        foreach ($dna['departures'] as $departure) {
            $texts["{$departure['id']}.result"] = $departure['result'];
        }

        foreach ($dna['features'] as $feature) {
            foreach (['name', 'what', 'why_new', 'plausibility'] as $field) {
                $texts["{$feature['id']}.{$field}"] = $feature[$field];
            }
        }

        foreach ($dna['keep'] as $index => $kept) {
            $texts["keep[{$index}]"] = $kept['feature'].' '.$kept['reason'];
        }

        foreach ($dna['must_avoid'] as $index => $item) {
            $texts["must_avoid[{$index}]"] = $item;
        }

        return $texts;
    }

    /**
     * @param  array<string, mixed>  $inspiration
     * @return list<string> every form of the names of the source build, its people and every other bike the article names, with and without a leading make, apart from the make and the model name of the base
     */
    private static function blockedNames(array $inspiration): array
    {
        $identity = is_array($inspiration['source_identity'] ?? null) ? $inspiration['source_identity'] : [];
        $names = [$inspiration['subject']['primary'] ?? null];

        foreach (['name', 'builder', 'designers'] as $field) {
            $names = [...$names, ...(array) (is_array($identity[$field] ?? null) ? ($identity[$field]['value'] ?? null) : null)];
        }

        foreach (is_array($inspiration['subject']['others'] ?? null) ? $inspiration['subject']['others'] : [] as $other) {
            if (is_array($other) && ! in_array($other['relation'] ?? null, self::BASE_RELATIONS, true)) {
                $names[] = $other['name'] ?? null;
            }
        }

        return MotoContract::blockedForms(
            DnaCheck::sourceNames(['name' => array_values(array_filter($names, 'is_string'))]),
            [$inspiration['base']['model']['value'] ?? null],
        );
    }

    /** @return array{version: string, format: string, verdict: string, issues: list<string>, schema_errors: list<string>, error: ?string} */
    private static function unscored(string $error): array
    {
        return [
            'version' => self::VERSION,
            'format' => MotoContract::DNA_FORMAT,
            'verdict' => DnaCheck::UNSCORED,
            'issues' => [],
            'schema_errors' => [],
            'error' => $error,
        ];
    }
}
