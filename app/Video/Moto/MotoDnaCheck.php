<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Video\DesignDna\DnaCheck;

final class MotoDnaCheck
{
    public const VERSION = 'moto-dna-check-v3';

    /** @var list<string> the self-checks of a moto-dna-v1 answer, in their fixed order */
    public const CHECKS = ['source_floor', 'brief', 'theme', 'departures', 'features', 'plausibility', 'coherence', 'silhouette', 'must_avoid', 'history', 'hero', 'contract_and_names'];

    /** @var list<string> the source lists whose ids a departure may cite */
    private const SOURCE_LISTS = ['styling', 'chassis', 'powertrain', 'technology', 'visuals'];

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
     * @param  string  $format  the answer format the run pinned
     * @return array{version: string, format: string, verdict: string, issues: list<string>, schema_errors: list<string>, error: ?string}
     */
    public static function evaluate(?array $answer, array $inspiration, array $flags, array $brief, array $history, array $schemaErrors, string $format = MotoContract::DNA_FORMAT): array
    {
        if ($answer === null) {
            return self::unscored('Phản hồi không đọc được JSON.', $format);
        }

        if (($answer['format'] ?? null) !== $format) {
            return self::unscored('Lượt này ghim hợp đồng '.$format.' nhưng phản hồi khai format '.json_encode($answer['format'] ?? null).' — sai hợp đồng, không dùng tiếp.', $format);
        }

        if ($schemaErrors !== []) {
            return ['schema_errors' => $schemaErrors] + self::unscored('Phản hồi sai JSON Schema đã ghim: '.implode('; ', array_slice($schemaErrors, 0, 5)).'.', $format);
        }

        $dna = $answer['dna'];
        $issues = [];
        $sourceIds = self::departureIds($inspiration);

        foreach ($dna['departures'] as $departure) {
            if (! in_array($departure['from_id'], $sourceIds, true)) {
                $issues[] = Finding::requirement('MotoDnaCheck.evaluate.1', "{$departure['id']}: from_id \"{$departure['from_id']}\" không phải ID signature, styling, chassis, powertrain, technology hay visual nào của nguồn.");
            }
        }

        foreach (['departures' => 'D', 'features' => 'F'] as $list => $prefix) {
            foreach (array_column($dna[$list], 'id') as $index => $id) {
                if ($id !== $prefix.($index + 1)) {
                    $issues[] = Finding::requirement('MotoDnaCheck.evaluate.2', "{$list}[{$index}] có ID {$id}; ID phải đánh số liên tiếp từ {$prefix}1.");
                }
            }
        }

        $issues = [...$issues, ...self::briefIssues($dna['brief'], $brief)];

        return [
            'version' => self::VERSION,
            'format' => $format,
            'verdict' => $issues === [] ? DnaCheck::PASS : DnaCheck::FAIL,
            ...Finding::fields($issues, null, false),
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
                    $issues[] = Finding::blocking('MotoDnaCheck.briefIssues.1', "brief.{$group}.{$field}: phải chép đúng giá trị đã cho ".json_encode($value, JSON_UNESCAPED_UNICODE).'.');
                }
            }

            $basis = $fixed === [] ? 'decided' : 'provided';

            if (($written[$group]['basis'] ?? null) !== $basis) {
                $issues[] = Finding::blocking('MotoDnaCheck.briefIssues.2', "brief.{$group}.basis: phải là {$basis}.");
            }
        }

        foreach (['keep', 'change_scope'] as $list) {
            foreach (is_array($given[$list] ?? null) ? $given[$list] : [] as $value) {
                if (! in_array($value, $written[$list], true)) {
                    $issues[] = Finding::blocking('MotoDnaCheck.briefIssues.3', "brief.{$list}: thiếu mục đã cho ".json_encode($value, JSON_UNESCAPED_UNICODE).'.');
                }
            }
        }

        $limits = is_string($given['limits'] ?? null) ? $given['limits'] : null;

        if ($written['limits'] !== ['text' => $limits, 'basis' => $limits === null ? 'none' : 'provided']) {
            $issues[] = Finding::blocking('MotoDnaCheck.briefIssues.4', $limits === null ? 'brief.limits: chưa có giới hạn nào được cho — phải là text null, basis none.' : 'brief.limits: phải chép đúng giới hạn đã cho, basis provided.');
        }

        return $issues;
    }


    /** @return array{version: string, format: string, verdict: string, issues: list<string>, schema_errors: list<string>, error: ?string} */
    private static function unscored(string $error, string $format = MotoContract::DNA_FORMAT): array
    {
        return [
            'version' => self::VERSION,
            'format' => $format,
            'verdict' => DnaCheck::UNSCORED,
            'issues' => [],
            'schema_errors' => [],
            'error' => $error,
        ];
    }
}
