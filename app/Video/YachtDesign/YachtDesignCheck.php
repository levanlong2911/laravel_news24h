<?php

declare(strict_types=1);

namespace App\Video\YachtDesign;

use App\Video\DesignDna\DnaCheck;

final class YachtDesignCheck
{
    public const FORMAT = 'phase3-design-v2';

    public const LEGACY_FORMAT = 'phase3-design-v1';

    public const INPUT_FORMAT = 'phase3-input-v1';

    public const FORMAT_V3 = 'phase3-design-v3';

    public const INPUT_FORMAT_V2 = 'phase3-input-v2';

    public const VERSION = 'yacht-design-check-v10';

    /** @var list<string> the self-checks of a Phase3Design@2.3.0 answer, in their fixed order */
    public const CHECKS = ['dna_fidelity', 'specs_and_basis', 'geometry', 'exterior_and_innovations', 'interior_design', 'spatial_connections', 'operational_limits', 'build_states', 'materials_and_lighting', 'anchors', 'identity_and_names', 'contract'];

    private const DECK_HEIGHT_TOLERANCE = 0.1;

    private const SIZE_TOLERANCE_M = 0.1;

    /** @var float the largest difference allowed between one figure given in yacht_bible.specs and in canonical.dimensions, in the unit of that figure */
    private const SPEC_TOLERANCE = 0.01;

    /** @var list<string> the specs canonical.dimensions must also give */
    private const SHARED_SPECS = ['loa', 'beam'];

    private const SEA = 'SEA';

    private const YACHT_SCOPE = 'YACHT';

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNSCORED = 'UNSCORED';

    private const LOA = [110, 180];

    /** @var list<array{loa: array{0: int, 1: int}, beam: array{0: int, 1: int}, gross_tonnage: array{0: int, 1: int}, decks: array{0: int, 1: int}, guests: array{0: int, 1: int}, crew: array{0: int, 1: int}}> */
    private const BANDS = [
        ['loa' => [110, 130], 'beam' => [16, 19], 'gross_tonnage' => [3000, 6000], 'decks' => [6, 7], 'guests' => [12, 30], 'crew' => [35, 60]],
        ['loa' => [130, 155], 'beam' => [18, 22], 'gross_tonnage' => [5000, 10000], 'decks' => [7, 8], 'guests' => [12, 36], 'crew' => [50, 80]],
        ['loa' => [155, 180], 'beam' => [20, 25], 'gross_tonnage' => [8000, 16000], 'decks' => [7, 9], 'guests' => [12, 36], 'crew' => [70, 110]],
    ];

    /** @var array<string, array{0: int, 1: int}> */
    private const LEGACY_WORDS = ['yacht' => [60, 80], 'geometry' => [50, 70], 'finished_surface' => [20, 30], 'zone' => [30, 50]];

    /** @var list<string> */
    private const PASSAGES = ['door', 'opening', 'stair', 'lift', 'ramp', 'hatch'];

    /** @var list<string> the passages that stay on one level, so their two spaces share a deck */
    private const LEVEL_PASSAGES = ['door', 'opening'];

    /** @var string the opening words of a must_not written as an order instead of the thing to keep out */
    private const NEGATED_RULE = '/^\s*(do not|don\'t|no|not)\b/iu';

    /** @var string the words of an editorial rule of the film, which no image of the yacht can show */
    private const EDITORIAL_RULE = '/\b(spec[\s-]+cards?|title[\s-]+cards?|titles?|headlines?|captions?|subtitles?|on[\s-]+screen\s+text|narration|voice[\s-]*overs?)\b/iu';

    /** @var string a measure of an innovation's equipment in canonical.dimensions: the lowercase id of the innovation, then the name of the measure */
    private const INNOVATION_MEASURE = '/^n(\d+)_([a-z0-9]+(?:_[a-z0-9]+)*)$/';

    /** @var string a key of canonical.dimensions that claims an innovation, however it is written */
    private const INNOVATION_PREFIX = '/^n\d+(?:[^a-z0-9]|$)/i';

    /** @var array<string, string> what a measure of an innovation's equipment may measure, with the one unit that quantity takes */
    private const QUANTITY_UNITS = ['length' => 'm', 'volume' => 'm3', 'mass' => 't'];

    /** @var string the words that describe equipment made of repeated parts a camera can count */
    private const REPEATED_PARTS = '/\b(facets|pockets|louvres|louvers|panels|slats|fins|mirrors|shutters|blades|apertures|tiles|rows|array|grid)\b/iu';

    /** @var list<string> */
    private const LIMIT_KINDS = ['innovation', 'part', 'space', 'activity'];

    /** @var list<string> */
    private const CANONICAL_STATES = ['BS05', 'BS06', 'BS07', 'BS08', 'BS09', 'BS10', 'BS11', 'BS12'];

    /** @var list<string> */
    public const KINDS = ['scale_model', 'build', 'finished'];

    /** @var list<string> */
    private const SPECS = ['loa', 'beam', 'gross_tonnage', 'decks', 'guests', 'crew'];

    /** @var list<string> */
    private const COUNTS = ['decks', 'guests', 'crew'];

    /** @var array<string, string> */
    private const UNITS = ['loa' => 'm', 'beam' => 'm', 'gross_tonnage' => 'GT'];

    /** @var list<string> */
    public const BASES = ['CONCEPT_ASSUMPTION', 'DERIVED', 'TRACED'];

    /**
     * @param  array<mixed>|null  $response
     * @param  array<mixed>  $dna
     * @param  array<mixed>  $sourceIdentity
     * @param  list<string>  $forbidden
     * @param  list<string>  $schemaIssues
     * @param  array{profile_margin_m: float|int, near_x: float|int, near_z_m: float|int}  $limits
     * @param  array<string, int>  $wordLimits  the maximum words of each anchor path, from the x-word-limits of the schemas the run was sent with; read for the current format only
     * @param  list<string>  $handoff  what keeps the model sheet from opening this design, from the model sheet's own blocking checks; read for the current format only
     * @param  ?string  $promptVersion  the prompt version the run was sent with, which the answer copies; null skips the comparison
     * @return array{version: string, format: string, verdict: string, issues: list<string>, warnings: list<string>, error: ?string}
     */
    public static function evaluate(?array $response, string $projectId, array $dna, array $sourceIdentity, array $forbidden, array $schemaIssues, BuildStateText $text, array $limits, array $wordLimits = [], array $handoff = [], ?string $promptVersion = null): array
    {
        if ($response === null) {
            return self::result(self::UNSCORED, [], 'Phản hồi không đọc được JSON.');
        }

        $format = $response['format'] ?? null;

        if ($format === self::FORMAT_V3) {
            return self::record($response, $projectId, $schemaIssues, $promptVersion);
        }

        if (! in_array($format, [self::FORMAT, self::LEGACY_FORMAT], true)) {
            return self::result(self::UNSCORED, [], 'Phản hồi không theo định dạng '.self::FORMAT.'.');
        }

        $legacy = $format === self::LEGACY_FORMAT;
        $status = $response['status'] ?? null;

        if ($status === 'fail') {
            return self::result(self::FAIL, [
                self::failure($response),
                ...array_map(static fn (string $issue): string => "Schema: {$issue}", $schemaIssues),
            ], null, [], $format);
        }

        $envelope = self::envelopeErrors($response, $projectId);

        if ($envelope !== []) {
            return self::result(self::UNSCORED, [], 'Phản hồi thiếu hoặc sai cấu trúc: '.implode('; ', $envelope).'.', [], $format);
        }

        $data = $response['data'];
        $bible = $data['yacht_bible'];
        $anchors = $data['anchors'];
        $canonical = $data['canonical'];
        [$issues, $broken] = self::fieldErrors($data);
        $ok = static fn (string ...$keys): bool => array_intersect($keys, $broken) === [];
        $warnings = [];

        if ($promptVersion !== null && ($response['prompt_version'] ?? null) !== $promptVersion) {
            $issues[] = 'prompt_version là '.json_encode($response['prompt_version'] ?? null, JSON_UNESCAPED_UNICODE)."; lượt chạy này dùng {$promptVersion}.";
        }

        if ($ok('specs.loa')) {
            $loa = self::spec($bible['specs'], 'loa');

            if ($loa < self::LOA[0] || $loa > self::LOA[1]) {
                $issues[] = "LOA {$loa} m nằm ngoài 110–180 m.";
            }

            if ($ok('specs.justifications')) {
                array_push($issues, ...self::specIssues($bible['specs'], $broken));
            }
        }

        if ($ok('rooms', 'exterior_zones', 'anchors.surface_states', 'anchors.exterior', 'anchors.interior')) {
            array_push($issues, ...self::anchorCoverage($bible, $anchors));
        }

        array_push($issues, ...self::bibleCanonicalMismatches($bible, $canonical, $broken, $legacy));

        if ($legacy) {
            foreach (['yacht', 'geometry', 'finished_surface'] as $key) {
                if ($ok("anchors.{$key}")) {
                    array_push($issues, ...self::wordIssues("anchors.{$key}", $anchors[$key], self::LEGACY_WORDS[$key]));
                }
            }

            foreach (['exterior', 'interior'] as $group) {
                foreach ($ok("anchors.{$group}") ? $anchors[$group] : [] as $id => $zone) {
                    array_push($issues, ...self::wordIssues("anchors.{$group}.{$id}", is_string($zone) ? $zone : '', self::LEGACY_WORDS['zone']));
                }
            }
        } else {
            [$anchorIssues, $anchorWarnings] = self::anchorIssues($bible, $anchors, $broken, $wordLimits);
            array_push($issues, ...$anchorIssues);
            array_push($warnings, ...$anchorWarnings);

            if ($ok('rooms', 'exterior_zones', 'innovations')) {
                array_push($issues, ...self::spatialIssues($bible, $canonical, $text));
                array_push($issues, ...self::measureIssues($bible, $canonical));
            }

            array_push($warnings, ...self::ruleWarnings($canonical), ...self::countWarnings($bible, $canonical));
        }

        foreach ($ok('colors', 'anchors.geometry') ? self::paletteTerms($bible, $canonical) : [] as $term) {
            if (mb_stripos($anchors['geometry'], $term) !== false) {
                $issues[] = "GEOMETRY ANCHOR chứa màu \"{$term}\".";
            }
        }

        $designText = implode("\n", self::strings([$bible, $anchors, $data['build_states']]));

        foreach ($forbidden as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/iu', $designText) === 1) {
                $issues[] = "Dùng từ cấm \"{$word}\".";
            }
        }

        foreach (DnaCheck::sourceNames($sourceIdentity) as $name) {
            if (preg_match('/\b'.preg_quote($name, '/').'\b/iu', $designText) === 1) {
                $issues[] = "Thiết kế dùng tên tàu nguồn \"{$name}\".";
            }

            $own = $ok('name') ? mb_strtolower(trim($bible['name'])) : '';
            $other = mb_strtolower($name);

            if ($own !== '' && (levenshtein($own, $other) <= 2 || str_contains($own, $other) || str_contains($other, $own))) {
                $issues[] = "Tên tàu \"{$bible['name']}\" gần trùng tên tàu nguồn \"{$name}\".";
            }
        }

        if ($ok('colors')) {
            if (count($bible['colors']) > 3) {
                $issues[] = 'Quá 3 màu ngoại thất.';
            }

            foreach ($bible['colors'] as $index => $color) {
                if (! is_array($color) || preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($color['hex'] ?? '')) !== 1) {
                    $issues[] = "Màu #".($index + 1).' thiếu HEX hợp lệ.';
                }
            }
        }

        if ($ok('innovations')) {
            $wanted = array_map(static fn (array $innovation): string => trim($innovation['id']), $dna['innovations'] ?? []);
            $placed = [];

            foreach ($bible['innovations'] as $innovation) {
                if (trim($innovation['position']) !== '') {
                    $placed[] = trim($innovation['id']);
                }
            }

            foreach (array_diff($wanted, $placed) as $missing) {
                $issues[] = "Innovation {$missing} của DNA chưa có vị trí trên tàu.";
            }

            if (! $legacy && $wanted !== []) {
                array_push($issues, ...self::innovationIdIssues($wanted, $bible, $canonical));
            }
        }

        if ($ok('rooms') && count($bible['rooms']) < 10) {
            $issues[] = 'Cần ít nhất 10 phòng, đang có '.count($bible['rooms']).'.';
        }

        foreach (array_map(static fn (int $n): string => sprintf('BS%02d', $n), range(1, 12)) as $id) {
            $sentence = $data['build_states'][$id] ?? null;

            if (! is_string($sentence) || ! str_starts_with(mb_strtolower(trim($sentence)), 'the yacht')) {
                $issues[] = "build_states.{$id} thiếu hoặc không bắt đầu bằng \"the yacht\".";
            }
        }

        array_push($issues, ...self::canonicalIssues($canonical, $text));

        [$landmarkIssues, $landmarkWarnings] = self::landmarkGeometry($canonical, $limits);
        array_push($issues, ...$landmarkIssues);
        array_push($warnings, ...$landmarkWarnings);

        foreach ($schemaIssues as $issue) {
            $issues[] = "Schema: {$issue}";
        }

        foreach ($legacy ? [] : $handoff as $problem) {
            $issues[] = "Model sheet: {$problem}";
        }

        if ($status !== 'ok') {
            $issues[] = 'AI báo status '.json_encode($status).' — hồ sơ chưa hoàn tất, không dùng cho các bước sau.';
        }

        return self::result($issues === [] ? self::PASS : self::FAIL, $issues, null, $warnings, $format);
    }

    /**
     * @param  array<mixed>  $response  a phase3-design-v3 answer
     * @param  list<string>  $schemaIssues  the violations of the schema the run was sent with
     * @param  ?string  $promptVersion  the prompt version the run was sent with, which the answer copies; null skips the comparison
     * @return array{version: string, format: string, verdict: string, issues: list<string>, warnings: list<string>, error: ?string}
     */
    private static function record(array $response, string $projectId, array $schemaIssues, ?string $promptVersion): array
    {
        $schema = array_map(static fn (string $issue): string => "Schema: {$issue}", $schemaIssues);
        $status = $response['status'] ?? null;

        if ($status === 'fail') {
            return self::result(self::FAIL, [self::failure($response), ...$schema], null, [], self::FORMAT_V3);
        }

        $issues = [];

        if (($response['phase'] ?? null) !== '3') {
            $issues[] = 'phase phải là "3".';
        }

        if (($response['project_id'] ?? null) !== $projectId) {
            $issues[] = 'project_id không phải dự án này.';
        }

        if ($promptVersion !== null && ($response['prompt_version'] ?? null) !== $promptVersion) {
            $issues[] = 'prompt_version là '.json_encode($response['prompt_version'] ?? null, JSON_UNESCAPED_UNICODE)."; lượt chạy này dùng {$promptVersion}.";
        }

        array_push($issues, ...$schema);

        if ($status !== 'ok') {
            $issues[] = 'AI báo status '.json_encode($status).' — hồ sơ chưa hoàn tất, không dùng cho các bước sau.';
        }

        return self::result($issues === [] ? self::PASS : self::FAIL, $issues, null, [], self::FORMAT_V3);
    }

    /** @param  array<mixed>  $response  an answer with status fail */
    private static function failure(array $response): string
    {
        $reasons = array_values(array_filter(array_map(static fn (mixed $flag): string => is_string($flag) ? rtrim(trim($flag), '.') : '', (array) ($response['flags'] ?? [])), static fn (string $flag): bool => $flag !== ''));

        return 'AI báo status fail — không tạo được hồ sơ thiết kế dùng được: '.($reasons !== [] ? implode('; ', $reasons) : 'không nêu lý do').'.';
    }

    /**
     * @param  list<string>  $wanted  the innovation ids of the DNA the design was made from
     * @param  array<mixed>  $bible
     * @param  array<mixed>  $canonical
     * @return list<string> every innovation id of yacht_bible or canonical that the DNA does not have, and every DNA id one of them lacks
     */
    private static function innovationIdIssues(array $wanted, array $bible, array $canonical): array
    {
        $issues = [];
        $ids = static fn (mixed $list): array => array_values(array_filter(array_map(static fn (mixed $item): ?string => is_array($item) && is_string($item['id'] ?? null) ? trim($item['id']) : null, is_array($list) ? $list : [])));

        foreach (['yacht_bible.innovations' => $ids($bible['innovations'] ?? null), 'canonical.innovations' => $ids($canonical['innovations'] ?? null)] as $label => $found) {
            foreach (array_diff($found, $wanted) as $extra) {
                $issues[] = "{$label} có {$extra}, không phải innovation của DNA (".implode(', ', $wanted).').';
            }

            foreach ($label === 'canonical.innovations' ? array_diff($wanted, $found) : [] as $missing) {
                $issues[] = "{$label} thiếu {$missing} của DNA.";
            }
        }

        return $issues;
    }

    /**
     * @param  array<mixed>  $canonical
     * @return list<string> every must_not whose text opens with a negation, which reads as a double negative once the system writes it after "Avoid:", and every rule that speaks of how the film presents the yacht rather than of what an image shows
     */
    private static function ruleWarnings(array $canonical): array
    {
        $warnings = [];

        foreach (is_array($canonical['identity_features'] ?? null) ? $canonical['identity_features'] : [] as $rule) {
            if (! is_array($rule) || ! is_string($rule['description'] ?? null)) {
                continue;
            }

            $id = (string) ($rule['id'] ?? '?');

            if (($rule['kind'] ?? null) === 'must_not' && preg_match(self::NEGATED_RULE, $rule['description'], $match) === 1) {
                $warnings[] = "identity_features {$id}: must_not mở đầu bằng \"{$match[1]}\" — ghi thứ không được xuất hiện bằng cụm danh từ, hệ thống tự thêm \"Avoid:\".";
            }

            if (preg_match(self::EDITORIAL_RULE, $rule['description'], $match) === 1) {
                $warnings[] = "identity_features {$id}: nhắc tới \"{$match[1]}\" — đây là luật biên tập của phim, không phải điều ảnh thấy được; luật này lọt vào model sheet và prompt bối cảnh, người duyệt quyết định thiết kế lại.";
            }
        }

        return $warnings;
    }

    /**
     * @param  array<mixed>  $bible
     * @param  array<mixed>  $canonical
     * @return list<string> every measure of an innovation in canonical.dimensions that is not named n<id>_<name> in lowercase for an existing innovation, does not say whether it is a length, a volume or a mass, gives another unit than its quantity takes or has no positive value; every two names of measures that are one once normalised; every key a visible element sizes itself with that is not a length of its own innovation
     */
    private static function measureIssues(array $bible, array $canonical): array
    {
        $issues = [];
        $dimensions = is_array($canonical['dimensions'] ?? null) ? $canonical['dimensions'] : [];
        $innovations = array_flip(array_map(static fn (mixed $innovation): string => is_array($innovation) ? (string) ($innovation['id'] ?? '') : '', is_array($bible['innovations'] ?? null) ? $bible['innovations'] : []));
        $names = [];

        foreach ($dimensions as $key => $measure) {
            $key = (string) $key;
            $names[(string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($key))][] = $key;

            if (preg_match(self::INNOVATION_PREFIX, $key) !== 1) {
                continue;
            }

            if (preg_match(self::INNOVATION_MEASURE, $key, $match) !== 1) {
                $issues[] = "canonical.dimensions.{$key}: số đo của innovation ghi tiền tố viết thường n<số>_ rồi tên thông số, ví dụ n1_facet_length.";

                continue;
            }

            if (! isset($innovations['N'.$match[1]])) {
                $issues[] = "canonical.dimensions.{$key}: không có innovation N{$match[1]}.";
            }

            $unit = is_array($measure) ? ($measure['unit'] ?? null) : null;
            $quantity = is_array($measure) ? ($measure['quantity'] ?? null) : null;
            $value = is_array($measure) ? ($measure['value'] ?? null) : null;

            if (! is_string($quantity) || ! isset(self::QUANTITY_UNITS[$quantity])) {
                $issues[] = "canonical.dimensions.{$key}: quantity ".json_encode($quantity, JSON_UNESCAPED_UNICODE).' không phải length, volume hoặc mass — ghi rõ số đo đo đại lượng gì.';
            } elseif ($unit !== self::QUANTITY_UNITS[$quantity]) {
                $issues[] = "canonical.dimensions.{$key}: {$quantity} ghi đơn vị ".json_encode($unit, JSON_UNESCAPED_UNICODE).', phải là '.self::QUANTITY_UNITS[$quantity].'.';
            }

            if ((! is_int($value) && ! is_float($value)) || $value <= 0) {
                $issues[] = "canonical.dimensions.{$key}: giá trị phải là một số dương.";
            }
        }

        foreach ($names as $same) {
            if (count($same) > 1) {
                $issues[] = 'canonical.dimensions: '.implode(', ', $same).' là cùng một số đo sau khi chuẩn hoá tên — ghi một lần.';
            }
        }

        foreach (is_array($canonical['innovations'] ?? null) ? $canonical['innovations'] : [] as $relation) {
            $id = is_array($relation) ? (string) ($relation['id'] ?? '') : '';

            foreach ($id === '' ? [] : array_filter((array) ($relation['visible_elements'] ?? []), 'is_array') as $index => $element) {
                foreach ((array) ($element['measures'] ?? []) as $key) {
                    $key = (string) $key;

                    if (! str_starts_with($key, mb_strtolower($id).'_')) {
                        $issues[] = "Innovation {$id}: visible_elements[{$index}] dùng số đo {$key} không thuộc {$id} — một phần tử chỉ mang số đo của chính innovation của nó.";
                    } elseif (! is_array($dimensions[$key] ?? null)) {
                        $issues[] = "Innovation {$id}: visible_elements[{$index}] tham chiếu {$key}, không có trong canonical.dimensions.";
                    } elseif (($dimensions[$key]['quantity'] ?? null) !== 'length') {
                        $issues[] = "Innovation {$id}: visible_elements[{$index}] tham chiếu {$key} quantity ".json_encode($dimensions[$key]['quantity'] ?? null).' — chỉ một length mô tả kích thước phần tử camera thấy; thể tích, khối lượng ở lại trong thiết kế.';
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<mixed>  $bible
     * @param  array<mixed>  $canonical
     * @return list<string> every innovation whose text describes repeated parts a camera can count while its relation gives no visible_elements, so images may show different counts
     */
    private static function countWarnings(array $bible, array $canonical): array
    {
        $warnings = [];
        $relations = array_column(array_filter(is_array($canonical['innovations'] ?? null) ? $canonical['innovations'] : [], 'is_array'), null, 'id');

        foreach (is_array($bible['innovations'] ?? null) ? $bible['innovations'] : [] as $innovation) {
            $id = is_array($innovation) ? (string) ($innovation['id'] ?? '') : '';
            $text = $id === '' ? '' : implode(' ', array_filter([$innovation['geometry'] ?? null, $innovation['visible_expression'] ?? null], 'is_string'));

            if ($text !== '' && isset($relations[$id]) && ($relations[$id]['visible_elements'] ?? []) === [] && preg_match_all(self::REPEATED_PARTS, $text, $matches) > 0) {
                $warnings[] = "Innovation {$id} tả phần tử lặp (".implode(', ', array_unique(array_map('mb_strtolower', $matches[1]))).') nhưng visible_elements rỗng — ghi số lượng và cách xếp nhìn thấy để mọi ảnh cùng một số.';
            }
        }

        return $warnings;
    }

    /**
     * @param  array<mixed>  $bible
     * @param  array<mixed>  $anchors
     * @param  list<string>  $broken
     * @param  array<string, int>  $wordLimits
     * @return array{0: list<string>, 1: list<string>} every anchor over its word ceiling, a yacht or geometry anchor without the LOA figure and a finished surface that names no exterior colour; then the space anchors that do not name their space
     */
    private static function anchorIssues(array $bible, array $anchors, array $broken, array $wordLimits): array
    {
        $ok = static fn (string ...$keys): bool => array_intersect($keys, $broken) === [];
        $issues = [];
        $warnings = [];

        if ($wordLimits === []) {
            $issues[] = 'Hợp đồng của lượt này không khai x-word-limits — không kiểm được độ dài anchor.';
        }

        foreach (['yacht', 'geometry', 'finished_surface'] as $key) {
            if ($ok("anchors.{$key}") && isset($wordLimits["anchors.{$key}"])) {
                array_push($issues, ...self::wordCeiling("anchors.{$key}", $anchors[$key], $wordLimits["anchors.{$key}"]));
            }
        }

        foreach (['exterior', 'interior'] as $group) {
            foreach ($ok("anchors.{$group}") && isset($wordLimits["anchors.{$group}.*"]) ? $anchors[$group] : [] as $id => $text) {
                array_push($issues, ...self::wordCeiling("anchors.{$group}.{$id}", is_string($text) ? $text : '', $wordLimits["anchors.{$group}.*"]));
            }
        }

        $loa = $ok('specs.loa') ? self::spec($bible['specs'], 'loa') : null;

        foreach ($loa === null ? [] : ['yacht', 'geometry'] as $key) {
            $figure = preg_quote(rtrim(rtrim(number_format((float) $loa, 2, '.', ''), '0'), '.'), '/');

            if ($ok("anchors.{$key}") && preg_match('/(?<![\d.])'.$figure.'(?:\s*-?\s*(?:m\b|metres?\b|meters?\b)|\b)/iu', $anchors[$key]) !== 1) {
                $issues[] = "anchors.{$key} không nêu LOA {$loa} m.";
            }
        }

        if ($ok('colors', 'anchors.finished_surface')) {
            $named = array_filter($bible['colors'], static fn (mixed $color): bool => is_array($color)
                && (mb_stripos($anchors['finished_surface'], trim((string) ($color['name'] ?? ''))) !== false && trim((string) ($color['name'] ?? '')) !== ''
                    || mb_stripos($anchors['finished_surface'], trim((string) ($color['hex'] ?? ''))) !== false && trim((string) ($color['hex'] ?? '')) !== ''));

            if ($named === []) {
                $issues[] = 'anchors.finished_surface không nêu tên hay mã màu ngoại thất nào của yacht_bible.colors.';
            }
        }

        foreach (['interior' => 'rooms', 'exterior' => 'exterior_zones'] as $group => $list) {
            foreach ($ok($list, "anchors.{$group}") ? $bible[$list] : [] as $space) {
                $name = is_array($space) ? trim((string) ($space['name'] ?? '')) : '';
                $text = is_array($space) ? ($anchors[$group][$space['id'] ?? ''] ?? null) : null;

                if ($name !== '' && is_string($text) && mb_stripos($text, $name) === false) {
                    $warnings[] = "anchors.{$group}.{$space['id']} không nêu tên không gian \"{$name}\".";
                }
            }
        }

        return [$issues, $warnings];
    }

    /**
     * @param  array<mixed>  $bible
     * @param  array<mixed>  $canonical
     * @return list<string> every broken id, reference, size, connection, route, innovation relation, identity scope and operational limit of the spatial structure
     */
    private static function spatialIssues(array $bible, array $canonical, BuildStateText $text): array
    {
        $issues = [];
        $list = static fn (mixed $value): array => is_array($value) && array_is_list($value) ? array_values(array_filter($value, 'is_array')) : [];
        $rooms = $list($bible['rooms'] ?? null);
        $zones = $list($bible['exterior_zones'] ?? null);
        $spaces = $list($canonical['spaces'] ?? null);
        $connections = $list($canonical['connections'] ?? null);
        $routes = $list($canonical['routes'] ?? null);
        $innovations = $list($canonical['innovations'] ?? null);
        $features = $list($canonical['identity_features'] ?? null);
        $decks = array_column($list($canonical['decks'] ?? null), null, 'name');
        $designed = [...array_column($rooms, 'id'), ...array_column($zones, 'id')];
        $known = array_flip(array_column($spaces, 'id'));
        $endpoint = static fn (mixed $id): bool => $id === self::SEA || (is_string($id) && isset($known[$id]));
        $builtIns = [];

        foreach ([
            'phòng và vùng' => $designed,
            'spaces' => array_column($spaces, 'id'),
            'connections' => array_column($connections, 'id'),
            'routes' => array_column($routes, 'id'),
            'identity_features' => array_column($features, 'id'),
            'yacht_bible.innovations' => array_column($list($bible['innovations'] ?? null), 'id'),
            'canonical.innovations' => array_column($innovations, 'id'),
        ] as $label => $ids) {
            foreach (array_keys(array_filter(array_count_values(array_map('strval', $ids)), static fn (int $count): bool => $count > 1)) as $twice) {
                $issues[] = "ID {$twice} lặp lại trong {$label}.";
            }
        }

        $bibleDecks = array_map('strval', array_column($list($bible['decks'] ?? null), 'name'));
        $canonicalDecks = array_map('strval', array_column($list($canonical['decks'] ?? null), 'name'));

        foreach (['yacht_bible.decks' => $bibleDecks, 'canonical.decks' => $canonicalDecks] as $label => $names) {
            foreach (array_keys(array_filter(array_count_values($names), static fn (int $count): bool => $count > 1)) as $twice) {
                $issues[] = "Boong {$twice} lặp lại trong {$label}.";
            }
        }

        foreach (array_unique(array_diff($bibleDecks, $canonicalDecks)) as $missing) {
            $issues[] = "Boong {$missing} có trong yacht_bible.decks nhưng thiếu trong canonical.decks — hai danh sách ghi cùng các boong, cùng tên.";
        }

        foreach (array_unique(array_diff($canonicalDecks, $bibleDecks)) as $extra) {
            $issues[] = "Boong {$extra} có trong canonical.decks nhưng thiếu trong yacht_bible.decks — hai danh sách ghi cùng các boong, cùng tên.";
        }

        foreach (array_diff($designed, array_keys($known)) as $missing) {
            $issues[] = "{$missing} không có mục trong canonical.spaces.";
        }

        foreach (array_diff(array_keys($known), $designed) as $extra) {
            $issues[] = "canonical.spaces.{$extra} không ứng với phòng hay vùng nào của yacht_bible.";
        }

        foreach ($rooms as $room) {
            $own = [];

            foreach ($list($room['built_ins'] ?? null) as $piece) {
                $id = (string) ($piece['id'] ?? '');

                if (! str_starts_with($id, $room['id'].'-B')) {
                    $issues[] = "Đồ đóng liền {$id} của {$room['id']} phải mang tiền tố {$room['id']}-B.";
                }

                if (isset($builtIns[$id])) {
                    $issues[] = "Đồ đóng liền {$id} lặp lại.";
                }

                $builtIns[$id] = true;
                $own[$id] = true;
            }

            foreach ($list($room['surfaces'] ?? null) as $surface) {
                if (is_string($surface['built_in'] ?? null) && ! isset($own[$surface['built_in']])) {
                    $issues[] = "Bề mặt \"{$surface['element']}\" của {$room['id']} trỏ tới {$surface['built_in']}, không phải đồ đóng liền của phòng này.";
                }
            }
        }

        $loa = (float) ($canonical['dimensions']['loa']['value'] ?? 0);
        $beam = (float) ($canonical['dimensions']['beam']['value'] ?? 0);

        foreach ($spaces as $space) {
            $id = (string) ($space['id'] ?? '?');
            $listed = array_values(array_filter((array) ($space['decks'] ?? []), 'is_string'));
            $names = array_values(array_unique($listed));
            $unknown = array_diff($names, array_keys($decks));

            foreach (array_keys(array_filter(array_count_values($listed), static fn (int $count): bool => $count > 1)) as $twice) {
                $issues[] = "{$id} ghi boong {$twice} nhiều lần trong decks — mỗi boong một lần, chiều cao boong chỉ tính một lần.";
            }

            if ($unknown !== []) {
                $issues[] = "{$id} nằm trên boong không có trong canonical.decks: ".implode(', ', $unknown).'.';
            }

            $size = (array) ($space['dimensions'] ?? []);
            [$length, $width, $tall] = array_map(static fn (string $axis): ?float => is_int($size[$axis]['value'] ?? null) || is_float($size[$axis]['value'] ?? null) ? (float) $size[$axis]['value'] : null, ['length_m', 'width_m', 'height_m']);
            $height = array_sum(array_map(static fn (string $deck): float => (float) ($decks[$deck]['height_m'] ?? 0), array_intersect($names, array_keys($decks))));

            if ($length !== null && $loa > 0 && $length > $loa + self::SIZE_TOLERANCE_M) {
                $issues[] = "{$id} dài {$length} m, hơn LOA {$loa} m.";
            }

            if ($width !== null && $beam > 0 && $width > $beam + self::SIZE_TOLERANCE_M) {
                $issues[] = "{$id} rộng {$width} m, hơn beam {$beam} m.";
            }

            if ($tall !== null && $unknown === [] && $names !== [] && $tall > $height + self::SIZE_TOLERANCE_M) {
                $issues[] = "{$id} cao {$tall} m, hơn tổng chiều cao ".round($height, 2).' m của '.(count($names) > 1 ? 'các boong '.implode(', ', $names) : 'boong '.$names[0]).'.';
            }
        }

        $byId = array_column($connections, null, 'id');
        $seen = [];
        $decksOf = array_column($spaces, 'decks', 'id');
        $deckOf = static fn (string $id): array => array_values(array_filter((array) ($decksOf[$id] ?? []), 'is_string'));

        foreach ($connections as $connection) {
            $id = (string) ($connection['id'] ?? '?');
            $between = array_values((array) ($connection['between'] ?? []));
            $users = array_values((array) ($connection['users'] ?? []));
            $kind = (string) ($connection['kind'] ?? '');

            if (count($between) !== 2 || $between[0] === $between[1]) {
                $issues[] = "Kết nối {$id} phải nối hai không gian khác nhau.";

                continue;
            }

            foreach (array_filter($between, static fn (mixed $end): bool => ! $endpoint($end)) as $end) {
                $issues[] = "Kết nối {$id} trỏ tới {$end}, không có trong canonical.spaces.";
            }

            if ($kind === 'adjacent' && in_array(self::SEA, $between, true)) {
                $issues[] = "Kết nối {$id}: liền kề với SEA không có nghĩa — lối ra nước là một khoảng mở hay cửa.";
            }

            if ($kind === 'adjacent' && $users !== []) {
                $issues[] = "Kết nối {$id} là liền kề, không có lối đi nên không có người đi qua.";
            }

            if (in_array($kind, self::PASSAGES, true) && $users === []) {
                $issues[] = "Kết nối {$id} là lối đi ({$kind}) nhưng không ghi ai được đi qua.";
            }

            if (in_array($kind, self::LEVEL_PASSAGES, true) && ! in_array(self::SEA, $between, true) && $endpoint($between[0]) && $endpoint($between[1])
                && array_intersect($deckOf($between[0]), $deckOf($between[1])) === []) {
                $issues[] = "Kết nối {$id} là {$kind} giữa {$between[0]} (".implode(', ', $deckOf($between[0])).") và {$between[1]} (".implode(', ', $deckOf($between[1])).') không chung boong nào — lối đổi tầng ghi stair, lift, ramp hoặc hatch.';
            }

            $pair = $between;
            sort($pair);
            $seen[$kind.'|'.implode('|', $pair)][] = ['id' => $id, 'note' => trim((string) ($connection['note'] ?? ''))];
        }

        foreach ($seen as $key => $same) {
            if (count($same) < 2) {
                continue;
            }

            [$kind, $first, $second] = explode('|', $key);
            $ids = implode(', ', array_column($same, 'id'));
            $notes = array_column($same, 'note');

            if ($kind === 'adjacent') {
                $issues[] = "Kết nối {$ids} cùng ghi {$first} và {$second} liền kề — liền kề là một sự thật, ghi một lần.";
            } elseif (in_array('', $notes, true) || count(array_unique(array_map('mb_strtolower', $notes))) !== count($notes)) {
                $issues[] = "Kết nối {$ids} cùng là {$kind} giữa {$first} và {$second}: mỗi lối đi ghi note vị trí riêng, khác nhau, để phân biệt hai cửa thật với một cửa ghi hai lần.";
            }
        }

        foreach ($routes as $route) {
            $id = (string) ($route['id'] ?? '?');
            $path = array_values((array) ($route['spaces'] ?? []));
            $via = array_values((array) ($route['via'] ?? []));
            $users = array_values((array) ($route['users'] ?? []));

            foreach (array_filter($path, static fn (mixed $end): bool => ! $endpoint($end)) as $end) {
                $issues[] = "Tuyến {$id} đi qua {$end}, không có trong canonical.spaces.";
            }

            if (count($via) !== count($path) - 1) {
                $issues[] = "Tuyến {$id} có ".count($path).' không gian nhưng '.count($via).' kết nối; cần đúng một kết nối giữa mỗi cặp liền nhau.';

                continue;
            }

            foreach ($via as $index => $connectionId) {
                $connection = $byId[$connectionId] ?? null;
                $step = [$path[$index], $path[$index + 1]];

                if ($connection === null) {
                    $issues[] = "Tuyến {$id} dùng kết nối {$connectionId} không có trong canonical.connections.";

                    continue;
                }

                $ends = array_values((array) $connection['between']);
                sort($ends);
                sort($step);

                if ($ends !== $step) {
                    $issues[] = "Tuyến {$id}: kết nối {$connectionId} không nối {$path[$index]} với {$path[$index + 1]}.";
                }

                if (($connection['kind'] ?? null) === 'adjacent') {
                    $issues[] = "Tuyến {$id} đi qua {$connectionId}, một chỗ liền kề không có lối đi.";
                }

                foreach (array_diff($users, (array) ($connection['users'] ?? [])) as $user) {
                    $issues[] = "Tuyến {$id} dành cho {$user} nhưng kết nối {$connectionId} không cho {$user} đi qua.";
                }
            }
        }

        $bibleInnovations = array_column($list($bible['innovations'] ?? null), null, 'id');
        $related = array_column($innovations, null, 'id');

        foreach (array_diff(array_keys($bibleInnovations), array_keys($related)) as $missing) {
            $issues[] = "Innovation {$missing} chưa có quan hệ trong canonical.innovations.";
        }

        foreach (array_diff(array_keys($related), array_keys($bibleInnovations)) as $extra) {
            $issues[] = "canonical.innovations.{$extra} không ứng với innovation nào của yacht_bible.";
        }

        foreach ($related as $id => $relation) {
            foreach (['mounted_in', 'serves', 'effect_in'] as $kind) {
                foreach (array_filter((array) ($relation[$kind] ?? []), static fn (mixed $space): bool => ! is_string($space) || ! isset($known[$space])) as $space) {
                    $issues[] = "Innovation {$id}: {$kind} trỏ tới {$space}, không có trong canonical.spaces.";
                }
            }

            $effect = $bibleInnovations[$id]['effect'] ?? null;

            if (! is_string($effect) && (array) ($relation['effect_in'] ?? []) !== []) {
                $issues[] = "Innovation {$id} không có effect nhưng effect_in lại ghi nơi thấy hiệu ứng.";
            }

            if (is_string($effect) && (array) ($relation['effect_in'] ?? []) === []) {
                $issues[] = "Innovation {$id} có effect nhưng effect_in không ghi nơi nào thấy hiệu ứng đó.";
            }

            $elements = array_map(
                static fn (array $element): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) ($element['element'] ?? '')))),
                $list($relation['visible_elements'] ?? null),
            );

            foreach (array_filter(array_count_values($elements), static fn (int $count): bool => $count > 1) as $element => $count) {
                $issues[] = "Innovation {$id}: visible_elements khai \"{$element}\" {$count} lần — mỗi loại phần tử hay mỗi nhóm khai một số lượng; hai nhóm khác nhau thì đặt tên phân biệt (forward …, aft …).";
            }
        }

        foreach ($features as $feature) {
            foreach (array_filter((array) ($feature['scope'] ?? []), static fn (mixed $scope): bool => $scope !== self::YACHT_SCOPE && (! is_string($scope) || ! isset($known[$scope]))) as $scope) {
                $issues[] = "identity_features.{$feature['id']}: phạm vi {$scope} không có trong canonical.spaces.";
            }
        }

        $finished = array_values(array_filter($list($canonical['build_states'] ?? null), static fn (array $state): bool => ($state['id'] ?? null) === 'BS12'))[0] ?? [];

        foreach ($list($canonical['operational_limits'] ?? null) as $limit) {
            $feature = (string) ($limit['feature'] ?? '?');
            $kind = $limit['applies_to']['kind'] ?? null;
            $target = $limit['applies_to']['id'] ?? null;

            $problem = match (true) {
                ! in_array($kind, self::LIMIT_KINDS, true) => 'applies_to.kind phải là một trong '.implode(', ', self::LIMIT_KINDS),
                $kind === 'innovation' && ! isset($related[$target]) => "applies_to trỏ tới innovation {$target} không có",
                $kind === 'space' && (! is_string($target) || ! isset($known[$target])) => "applies_to trỏ tới không gian {$target} không có",
                $kind === 'part' && (! is_string($target) || ! $text->isPart($target)) => "applies_to trỏ tới bộ phận {$target} ngoài danh mục BuildState",
                $kind === 'part' && ! in_array($target, (array) ($finished['present_parts'] ?? []), true) => "giới hạn cho bộ phận {$target} mà BS12 không có",
                $kind === 'activity' && $target !== null => 'applies_to.kind activity có id null',
                default => null,
            };

            if ($problem !== null) {
                $issues[] = "operational_limits.{$feature}: {$problem}.";
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private static function wordCeiling(string $label, string $text, int $max): array
    {
        $words = self::words($text);

        return $words > $max ? ["{$label} có {$words} từ, tối đa {$max}."] : [];
    }

    private static function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * @param  array<mixed>  $response
     * @return list<string>
     */
    private static function envelopeErrors(array $response, string $projectId): array
    {
        $errors = [];

        if (($response['phase'] ?? null) !== '3') {
            $errors[] = 'phase phải là "3"';
        }

        if (($response['project_id'] ?? null) !== $projectId) {
            $errors[] = 'project_id không phải dự án này';
        }

        $data = $response['data'] ?? null;

        if (! self::isObject($data)) {
            return [...$errors, 'data không phải object'];
        }

        foreach (['yacht_bible', 'anchors', 'build_states', 'canonical'] as $key) {
            if (! self::isObject($data[$key] ?? null)) {
                $errors[] = "data.{$key} không phải object";
            }
        }

        return $errors;
    }

    /**
     * @param  array<mixed>  $data
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function fieldErrors(array $data): array
    {
        $errors = [];
        $broken = [];
        $fail = static function (string $key, string $message) use (&$errors, &$broken): void {
            $errors[] = "Cấu trúc: {$message}.";
            $broken[] = $key;
        };
        $list = static fn (mixed $value): bool => is_array($value) && array_is_list($value);
        $bible = $data['yacht_bible'];
        $specs = $bible['specs'] ?? null;

        if (! is_string($bible['name'] ?? null)) {
            $fail('name', 'yacht_bible.name phải là chuỗi');
        }

        if (! self::isObject($specs)) {
            $fail('specs.justifications', 'yacht_bible.specs không phải object');
            array_push($broken, ...array_map(static fn (string $key): string => "specs.{$key}", self::SPECS));
        } else {
            foreach (self::SPECS as $key) {
                $problems = array_key_exists($key, $specs) ? self::specProblems($key, $specs[$key]) : ['bị thiếu'];

                if ($problems !== []) {
                    $fail("specs.{$key}", "yacht_bible.specs.{$key} ".implode('; ', $problems));
                }
            }

            if (! self::isObject($specs['justifications'] ?? null)) {
                $fail('specs.justifications', 'yacht_bible.specs.justifications không phải object');
            }
        }

        foreach (['colors', 'innovations', 'exterior_zones', 'rooms'] as $key) {
            if (! $list($bible[$key] ?? null)) {
                $fail($key, "yacht_bible.{$key} phải là danh sách");
            }
        }

        foreach ($list($bible['innovations'] ?? null) ? $bible['innovations'] : [] as $index => $innovation) {
            if (! is_string($innovation['id'] ?? null) || ! is_string($innovation['position'] ?? null)) {
                $fail('innovations', "yacht_bible.innovations[{$index}] cần id và position là chuỗi");
            }
        }

        $anchors = $data['anchors'];

        foreach (['yacht', 'geometry', 'finished_surface'] as $key) {
            if (! is_string($anchors[$key] ?? null)) {
                $fail("anchors.{$key}", "anchors.{$key} phải là chuỗi");
            }
        }

        foreach (['surface_states', 'exterior', 'interior'] as $key) {
            if (! self::isObject($anchors[$key] ?? null)) {
                $fail("anchors.{$key}", "anchors.{$key} không phải object");
            }
        }

        return [$errors, array_values(array_unique($broken))];
    }

    /** @return list<string> */
    private static function specProblems(string $key, mixed $spec): array
    {
        if ($key === 'gross_tonnage' && $spec === null) {
            return [];
        }

        if (! self::isObject($spec)) {
            return ['phải là {"value", "unit", "basis"}'];
        }

        $problems = [];
        $value = $spec['value'] ?? null;
        $unit = $spec['unit'] ?? null;

        if (in_array($key, self::COUNTS, true) ? ! is_int($value) : ! (is_int($value) || is_float($value))) {
            $problems[] = 'value phải là '.(in_array($key, self::COUNTS, true) ? 'số nguyên' : 'số');
        }

        if (isset(self::UNITS[$key]) ? $unit !== self::UNITS[$key] : ! (is_string($unit) && trim($unit) !== '')) {
            $problems[] = isset(self::UNITS[$key]) ? 'unit phải là "'.self::UNITS[$key].'"' : 'unit phải là chuỗi khác rỗng';
        }

        if (! in_array($spec['basis'] ?? null, self::BASES, true)) {
            $problems[] = 'basis phải là một trong '.implode(', ', self::BASES);
        }

        return $problems;
    }

    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    /**
     * @param  array<string, mixed>  $specs
     */
    private static function spec(array $specs, string $key): int|float|null
    {
        return is_array($specs[$key] ?? null) ? $specs[$key]['value'] : null;
    }

    /**
     * @param  array<string, mixed>  $specs
     * @param  list<string>  $broken
     * @return list<string>
     */
    private static function specIssues(array $specs, array $broken): array
    {
        $loa = (float) self::spec($specs, 'loa');
        $band = null;

        foreach (self::BANDS as $candidate) {
            if ($loa >= $candidate['loa'][0] && $loa <= $candidate['loa'][1]) {
                $band = $candidate;

                break;
            }
        }

        if ($band === null) {
            return [];
        }

        $issues = [];
        $checked = array_values(array_filter(array_slice(self::SPECS, 1), static fn (string $key): bool => ! in_array("specs.{$key}", $broken, true)));
        $values = array_combine($checked, array_map(static fn (string $key): int|float|null => self::spec($specs, $key), $checked));

        foreach ($values as $key => $value) {
            if ($value === null || ($value >= $band[$key][0] && $value <= $band[$key][1])) {
                continue;
            }

            $note = $specs['justifications'][$key] ?? null;

            if (! is_string($note) || trim($note) === '') {
                $issues[] = "{$key} = {$value} ngoài khoảng {$band[$key][0]}–{$band[$key][1]} cho LOA {$loa} m và không có câu giải thích.";
            }
        }

        return $issues;
    }

    /**
     * @param  array<mixed>  $bible
     * @param  array<mixed>  $anchors
     * @return list<string>
     */
    private static function anchorCoverage(array $bible, array $anchors): array
    {
        $issues = [];

        foreach (['interior' => 'rooms', 'exterior' => 'exterior_zones'] as $group => $list) {
            $ids = array_values(array_filter(array_map(static fn (mixed $item): string => is_array($item) ? trim((string) ($item['id'] ?? '')) : '', $bible[$list])));
            $anchored = array_keys(array_filter($anchors[$group], static fn (mixed $text): bool => is_string($text) && trim($text) !== ''));

            foreach (array_diff($ids, $anchored) as $missing) {
                $issues[] = "{$missing} thiếu anchors.{$group}.";
            }

            foreach (array_diff($anchored, $ids) as $extra) {
                $issues[] = "anchors.{$group}.{$extra} không ứng với mục nào trong yacht_bible.{$list}.";
            }
        }

        $states = array_map(static fn (int $n): string => sprintf('BS%02d', $n), range(6, 12));
        $written = array_keys(array_filter($anchors['surface_states'], static fn (mixed $text): bool => is_string($text) && trim($text) !== ''));

        foreach (array_diff($states, $written) as $missing) {
            $issues[] = "anchors.surface_states thiếu {$missing}.";
        }

        foreach (array_diff($written, $states) as $extra) {
            $issues[] = "anchors.surface_states.{$extra} nằm ngoài BS06–BS12.";
        }

        return $issues;
    }

    /**
     * @param  array<mixed>  $bible
     * @param  array<mixed>  $canonical
     * @param  list<string>  $broken
     * @param  bool  $legacy  an answer of the older format, whose rooms are listed again in canonical.rooms
     * @return list<string>
     */
    private static function bibleCanonicalMismatches(array $bible, array $canonical, array $broken, bool $legacy): array
    {
        $ok = static fn (string $key): bool => ! in_array($key, $broken, true);
        $issues = [];
        $decks = is_array($canonical['decks'] ?? null) ? count($canonical['decks']) : null;
        $hexes = static fn (mixed $colors): array => array_values(array_unique(array_map(
            static fn (mixed $color): string => is_array($color) ? strtoupper((string) ($color['hex'] ?? '')) : '',
            is_array($colors) ? $colors : [],
        )));
        $ids = static fn (mixed $items): array => array_values(array_unique(array_map(
            static fn (mixed $item): string => is_array($item) ? trim((string) ($item['id'] ?? '')) : '',
            is_array($items) ? $items : [],
        )));

        $bibleDecks = $ok('specs.decks') ? self::spec($bible['specs'], 'decks') : null;

        if ($ok('specs.decks') && $decks !== $bibleDecks) {
            $issues[] = "Số boong không khớp: yacht_bible.specs.decks = {$bibleDecks}, canonical.decks có ".($decks ?? 'không rõ').'.';
        }

        if (self::isObject($bible['specs'] ?? null)) {
            array_push($issues, ...self::specDimensionIssues($bible['specs'], self::isObject($canonical['dimensions'] ?? null) ? $canonical['dimensions'] : []));
        }

        if ($ok('name') && trim((string) ($canonical['name'] ?? '')) !== trim($bible['name'])) {
            $issues[] = 'Tên tàu của yacht_bible và canonical không khớp.';
        }

        $bibleHex = $ok('colors') ? $hexes($bible['colors']) : [];
        $canonicalHex = $hexes($canonical['surfaces']['colors'] ?? []);
        sort($bibleHex);
        sort($canonicalHex);

        if ($ok('colors') && $bibleHex !== $canonicalHex) {
            $issues[] = 'Bảng màu của yacht_bible và canonical.surfaces.colors không khớp.';
        }

        $bibleRooms = $ok('rooms') ? $ids($bible['rooms']) : [];
        $canonicalRooms = $ids($canonical['rooms'] ?? []);
        sort($bibleRooms);
        sort($canonicalRooms);

        if ($legacy && $ok('rooms') && $bibleRooms !== $canonicalRooms) {
            $issues[] = 'Danh sách phòng của yacht_bible và canonical.rooms không khớp.';
        }

        return $issues;
    }

    /**
     * @param  array<mixed>  $specs  yacht_bible.specs
     * @param  array<mixed>  $dimensions  canonical.dimensions
     * @return list<string> every figure given in both places whose unit differs or whose value differs by more than SPEC_TOLERANCE, and every shared spec canonical.dimensions lacks; named by both paths, never corrected
     */
    private static function specDimensionIssues(array $specs, array $dimensions): array
    {
        $issues = [];
        $number = static fn (mixed $value): bool => is_int($value) || is_float($value);

        foreach ($specs as $key => $spec) {
            $dimension = $dimensions[$key] ?? null;

            if ($key === 'justifications' || ! is_array($spec)) {
                continue;
            }

            if (! is_array($dimension)) {
                if (in_array($key, self::SHARED_SPECS, true)) {
                    $issues[] = "canonical.dimensions.{$key} thiếu, trong khi yacht_bible.specs.{$key} có.";
                }

                continue;
            }

            $unit = trim((string) ($spec['unit'] ?? ''));
            $other = trim((string) ($dimension['unit'] ?? ''));

            if ($unit !== $other) {
                $issues[] = "yacht_bible.specs.{$key} dùng đơn vị \"{$unit}\", canonical.dimensions.{$key} dùng \"{$other}\".";
            }

            $value = $spec['value'] ?? null;
            $expected = $dimension['value'] ?? null;

            if ($number($value) && $number($expected) ? round(abs($value - $expected), 6) > self::SPEC_TOLERANCE : $value !== $expected) {
                $issues[] = "yacht_bible.specs.{$key} = ".json_encode($value)." nhưng canonical.dimensions.{$key} = ".json_encode($expected).' (lệch quá '.self::SPEC_TOLERANCE.').';
            }
        }

        return $issues;
    }

    /**
     * @param  array{0: int, 1: int}  $range
     * @return list<string>
     */
    private static function wordIssues(string $label, string $text, array $range): array
    {
        $words = count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return $words < $range[0] || $words > $range[1] ? ["{$label} có {$words} từ, cần {$range[0]}–{$range[1]}."] : [];
    }

    /**
     * @param  array<mixed>  $bible
     * @param  array<mixed>  $canonical
     * @return list<string>
     */
    private static function paletteTerms(array $bible, array $canonical): array
    {
        $terms = [];

        foreach ([...$bible['colors'], ...(array) ($canonical['surfaces']['colors'] ?? [])] as $color) {
            foreach (['name', 'hex'] as $key) {
                if (is_array($color) && is_string($color[$key] ?? null) && trim($color[$key]) !== '') {
                    $terms[] = trim($color[$key]);
                }
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * @param  array<mixed>  $canonical
     * @return list<string>
     */
    private static function canonicalIssues(array $canonical, BuildStateText $text): array
    {
        $issues = [];

        if (($canonical['revision'] ?? null) !== 0 || ($canonical['revision_kind'] ?? null) !== 'draft') {
            $issues[] = 'canonical phải là revision 0, revision_kind draft.';
        }

        array_push($issues, ...self::deckIssues($canonical['decks'] ?? null));
        array_push($issues, ...self::absentLandmarks($canonical, $text));

        $states = is_array($canonical['build_states'] ?? null) ? $canonical['build_states'] : [];
        $ids = array_map(static fn (mixed $state): string => is_array($state) ? (string) ($state['id'] ?? '') : '', $states);
        $sorted = $ids;
        sort($sorted);

        if ($sorted !== self::CANONICAL_STATES) {
            $issues[] = 'canonical.build_states phải gồm đúng BS05–BS12, mỗi trạng thái một lần.';
        }

        foreach ($states as $state) {
            if (! is_array($state)) {
                continue;
            }

            $id = (string) ($state['id'] ?? '?');
            $present = is_array($state['present_parts'] ?? null) ? $state['present_parts'] : [];
            $absent = is_array($state['absent_parts'] ?? null) ? $state['absent_parts'] : [];
            $surfaces = is_array($state['surfaces_by_part'] ?? null) ? array_keys($state['surfaces_by_part']) : [];
            $unknown = $text->unknown($state);

            if (! in_array($state['kind'] ?? null, self::KINDS, true)) {
                $issues[] = "{$id}: kind \"".(is_scalar($state['kind'] ?? null) ? $state['kind'] : '?').'" không thuộc '.implode(', ', self::KINDS).'.';
            }

            if ($unknown['parts'] !== []) {
                $issues[] = "{$id}: bộ phận ngoài danh mục BuildState: ".implode(', ', $unknown['parts']).'.';
            }

            if ($unknown['surfaces'] !== []) {
                $issues[] = "{$id}: bề mặt ngoài danh mục BuildState: ".implode(', ', $unknown['surfaces']).'.';
            }

            if (array_intersect($present, $absent) !== []) {
                $issues[] = "{$id}: bộ phận vừa có vừa thiếu: ".implode(', ', array_intersect($present, $absent)).'.';
            }

            if (array_diff($surfaces, $present) !== []) {
                $issues[] = "{$id}: gán bề mặt cho bộ phận không có mặt: ".implode(', ', array_diff($surfaces, $present)).'.';
            }

            if ($unknown['parts'] === [] && $unknown['surfaces'] === [] && trim((string) ($state['compiled_text'] ?? '')) === '') {
                $issues[] = "{$id}: không ghép được compiled_text từ bảng bộ phận và bề mặt.";
            }
        }

        return $issues;
    }

    /**
     * @param  array<mixed>  $canonical
     * @return list<string>
     */
    private static function absentLandmarks(array $canonical, BuildStateText $text): array
    {
        $landmarks = self::isObject($canonical['landmarks'] ?? null) ? $canonical['landmarks'] : [];
        $finished = array_values(array_filter(
            is_array($canonical['build_states'] ?? null) ? $canonical['build_states'] : [],
            static fn (mixed $state): bool => is_array($state) && ($state['id'] ?? null) === 'BS12',
        ))[0] ?? null;
        $absent = is_array($finished['absent_parts'] ?? null) ? $finished['absent_parts'] : [];
        $issues = [];

        foreach ($landmarks as $key => $landmark) {
            if (is_string($key) && is_array($landmark) && $text->isPart($key) && in_array($key, $absent, true)) {
                $issues[] = "landmarks.{$key}: BS12 ghi {$key} trong absent_parts nhưng vẫn có landmark.";
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private static function deckIssues(mixed $decks): array
    {
        if (! is_array($decks) || ! array_is_list($decks)) {
            return ['canonical.decks phải là danh sách.'];
        }

        $issues = [];
        $number = static fn (mixed $value): bool => is_int($value) || is_float($value);

        foreach ($decks as $index => $deck) {
            $label = "canonical.decks[{$index}]".(is_array($deck) && is_string($deck['name'] ?? null) ? " ({$deck['name']})" : '');

            if (! is_array($deck)) {
                $issues[] = "{$label} không phải object.";

                continue;
            }

            $missing = array_values(array_filter(['floor_z_m', 'ceiling_z_m', 'height_m'], static fn (string $key): bool => ! $number($deck[$key] ?? null)));

            if ($missing !== []) {
                $issues[] = "{$label}: ".implode(', ', $missing).' thiếu hoặc không phải số.';
            }

            $height = $deck['height_m'] ?? null;
            $floor = $deck['floor_z_m'] ?? null;
            $ceiling = $deck['ceiling_z_m'] ?? null;

            if ($number($height) && $height <= 0) {
                $issues[] = "{$label}: height_m = {$height} phải lớn hơn 0.";
            }

            if ($number($floor) && $number($ceiling) && $ceiling <= $floor) {
                $issues[] = "{$label}: ceiling_z_m = {$ceiling} phải cao hơn floor_z_m = {$floor}.";
            }

            if ($number($floor) && $number($ceiling) && $number($height) && abs($height - ($ceiling - $floor)) > self::DECK_HEIGHT_TOLERANCE + 1e-9) {
                $issues[] = "{$label}: height_m = {$height} lệch ceiling_z_m − floor_z_m = ".round($ceiling - $floor, 3).' quá '.self::DECK_HEIGHT_TOLERANCE.' m.';
            }
        }

        return $issues;
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $found = [];

        array_walk_recursive($values, static function (mixed $value) use (&$found): void {
            if (is_string($value)) {
                $found[] = $value;
            }
        });

        return $found;
    }

    /**
     * @param  array<mixed>  $canonical
     * @param  array{profile_margin_m: float|int, near_x: float|int, near_z_m: float|int}  $limits
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function landmarkGeometry(array $canonical, array $limits): array
    {
        $landmarks = self::isObject($canonical['landmarks'] ?? null) ? $canonical['landmarks'] : [];
        $number = static fn (mixed $value): bool => is_int($value) || is_float($value);
        $issues = [];
        $warnings = [];
        $placed = [];

        foreach ($landmarks as $key => $landmark) {
            if (! is_array($landmark)) {
                continue;
            }

            $declared = $landmark['above_profile'] ?? null;
            $reason = $landmark['above_profile_reason'] ?? null;

            if (array_key_exists('y', $landmark) && ! $number($landmark['y'])) {
                $issues[] = "landmarks.{$key}: y phải là số (mét từ trục giữa, dương về port).";
            }

            if (array_key_exists('above_profile', $landmark) && ! is_bool($declared)) {
                $issues[] = "landmarks.{$key}: above_profile phải là true hoặc false.";
            }

            if ($declared === true && ! (is_string($reason) && trim($reason) !== '')) {
                $issues[] = "landmarks.{$key}: above_profile = true cần above_profile_reason là chuỗi khác rỗng.";
            }

            if ($number($landmark['x'] ?? null) && $number($landmark['z'] ?? null)) {
                $placed[(string) $key] = $landmark;
            }
        }

        [$profile, $profileProblem] = self::profile($canonical['profile_curve'] ?? null);

        if ($profileProblem !== null && $placed !== []) {
            $warnings[] = "Cần xác nhận hình học: {$profileProblem}, chưa đối chiếu được landmark với đường bao.";
        }

        foreach ($placed as $key => $landmark) {
            if (($landmark['above_profile'] ?? null) === true) {
                $reason = $landmark['above_profile_reason'] ?? null;
                $warnings[] = "Cần đối chiếu ảnh: landmarks.{$key} khai nhô lên đường bao".(is_string($reason) && trim($reason) !== '' ? ' ('.trim($reason).')' : '').'.';

                continue;
            }

            if ($profileProblem !== null) {
                continue;
            }

            $envelope = self::profileAt($profile, $landmark['x']);

            if ($envelope === null) {
                $warnings[] = "Cần xác nhận hình học: landmarks.{$key} có x = {$landmark['x']} ngoài phạm vi profile_curve, không đủ dữ liệu để đối chiếu.";
            } elseif ($landmark['z'] > $envelope + $limits['profile_margin_m'] + 1e-9) {
                $warnings[] = "Cần xác nhận hình học: landmarks.{$key} cao {$landmark['z']} m, vượt profile_curve (".round($envelope, 2)." m tại x = {$landmark['x']}) quá {$limits['profile_margin_m']} m.";
            }
        }

        $keys = array_keys($placed);

        foreach ($keys as $i => $first) {
            foreach (array_slice($keys, $i + 1) as $second) {
                [$a, $b] = [$placed[$first], $placed[$second]];

                if (abs($a['x'] - $b['x']) <= $limits['near_x'] + 1e-9 && abs($a['z'] - $b['z']) <= $limits['near_z_m'] + 1e-9
                    && (! $number($a['y'] ?? null) || ! $number($b['y'] ?? null))) {
                    $warnings[] = "Cần xác nhận vị trí ngang: landmarks.{$first} và landmarks.{$second} gần trùng x/z, chưa phân biệt được vị trí ngang.";
                }
            }
        }

        return [$issues, $warnings];
    }

    /** @return array{0: list<array{0: int|float, 1: int|float}>, 1: ?string} */
    private static function profile(mixed $curve): array
    {
        if (! is_array($curve) || ! array_is_list($curve) || count($curve) < 2) {
            return [[], 'profile_curve thiếu hoặc ít hơn 2 điểm'];
        }

        foreach ($curve as $point) {
            if (! is_array($point) || ! array_is_list($point) || count($point) !== 2
                || ! (is_int($point[0]) || is_float($point[0])) || ! (is_int($point[1]) || is_float($point[1]))) {
                return [[], 'profile_curve có điểm không hợp lệ'];
            }
        }

        usort($curve, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        foreach (array_slice($curve, 1) as $index => $point) {
            if ($point[0] == $curve[$index][0]) {
                return [[], "profile_curve có điểm trùng x = {$point[0]}"];
            }
        }

        return [$curve, null];
    }

    /** @param  list<array{0: int|float, 1: int|float}>  $profile */
    private static function profileAt(array $profile, int|float $x): ?float
    {
        if ($x < $profile[0][0] || $x > $profile[count($profile) - 1][0]) {
            return null;
        }

        foreach (array_slice($profile, 1) as $index => [$x1, $z1]) {
            [$x0, $z0] = $profile[$index];

            if ($x <= $x1) {
                return (float) ($z0 + ($z1 - $z0) * ($x - $x0) / ($x1 - $x0));
            }
        }

        return (float) $profile[count($profile) - 1][1];
    }

    /**
     * @param  list<string>  $issues
     * @param  list<string>  $warnings
     * @return array{version: string, format: string, verdict: string, issues: list<string>, warnings: list<string>, error: ?string}
     */
    private static function result(string $verdict, array $issues, ?string $error, array $warnings = [], string $format = self::FORMAT): array
    {
        return ['version' => self::VERSION, 'format' => $format, 'verdict' => $verdict, 'issues' => $issues, 'warnings' => $warnings, 'error' => $error];
    }
}
