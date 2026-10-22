<?php

declare(strict_types=1);

namespace App\Video\YachtDesign;

use App\Video\DesignDna\DnaCheck;

final class YachtDesignCheck
{
    public const FORMAT = 'phase3-design-v1';

    public const VERSION = 'yacht-design-check-v1';

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
    private const WORDS = ['yacht' => [60, 80], 'geometry' => [50, 70], 'finished_surface' => [20, 30], 'zone' => [30, 50]];

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
     * @return array{version: string, format: string, verdict: string, issues: list<string>, error: ?string}
     */
    public static function evaluate(?array $response, string $projectId, array $dna, array $sourceIdentity, array $forbidden, array $schemaIssues, BuildStateText $text): array
    {
        if ($response === null) {
            return self::result(self::UNSCORED, [], 'Phản hồi không đọc được JSON.');
        }

        if (($response['format'] ?? null) !== self::FORMAT) {
            return self::result(self::UNSCORED, [], 'Phản hồi không theo định dạng '.self::FORMAT.'.');
        }

        $envelope = self::envelopeErrors($response, $projectId);

        if ($envelope !== []) {
            return self::result(self::UNSCORED, [], 'Phản hồi thiếu hoặc sai cấu trúc: '.implode('; ', $envelope).'.');
        }

        $data = $response['data'];
        $bible = $data['yacht_bible'];
        $anchors = $data['anchors'];
        $canonical = $data['canonical'];
        [$issues, $broken] = self::fieldErrors($data);
        $ok = static fn (string ...$keys): bool => array_intersect($keys, $broken) === [];

        if ($ok('specs.loa')) {
            $loa = self::spec($bible['specs'], 'loa');

            if ($loa < self::LOA[0] || $loa > self::LOA[1]) {
                $issues[] = "LOA {$loa} m nằm ngoài 110–180 m.";
            }

            if (($canonical['dimensions']['loa']['value'] ?? null) != $loa) {
                $issues[] = 'LOA của yacht_bible và canonical không khớp.';
            }

            if ($ok('specs.justifications')) {
                array_push($issues, ...self::specIssues($bible['specs'], $broken));
            }
        }

        if ($ok('rooms', 'exterior_zones', 'anchors.surface_states', 'anchors.exterior', 'anchors.interior')) {
            array_push($issues, ...self::anchorCoverage($bible, $anchors));
        }

        array_push($issues, ...self::bibleCanonicalMismatches($bible, $canonical, $broken));

        foreach (['yacht', 'geometry', 'finished_surface'] as $key) {
            if ($ok("anchors.{$key}")) {
                array_push($issues, ...self::wordIssues("anchors.{$key}", $anchors[$key], self::WORDS[$key]));
            }
        }

        foreach (['exterior', 'interior'] as $group) {
            foreach ($ok("anchors.{$group}") ? $anchors[$group] : [] as $id => $zone) {
                array_push($issues, ...self::wordIssues("anchors.{$group}.{$id}", is_string($zone) ? $zone : '', self::WORDS['zone']));
            }
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

        foreach ($schemaIssues as $issue) {
            $issues[] = "Schema: {$issue}";
        }

        return self::result($issues === [] ? self::PASS : self::FAIL, $issues, null);
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
     * @return list<string>
     */
    private static function bibleCanonicalMismatches(array $bible, array $canonical, array $broken): array
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

        if ($ok('specs.beam') && ($canonical['dimensions']['beam']['value'] ?? null) != self::spec($bible['specs'], 'beam')) {
            $issues[] = 'Beam của yacht_bible và canonical không khớp.';
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

        if ($ok('rooms') && $bibleRooms !== $canonicalRooms) {
            $issues[] = 'Danh sách phòng của yacht_bible và canonical.rooms không khớp.';
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
     * @param  list<string>  $issues
     * @return array{version: string, format: string, verdict: string, issues: list<string>, error: ?string}
     */
    private static function result(string $verdict, array $issues, ?string $error): array
    {
        return ['version' => self::VERSION, 'format' => self::FORMAT, 'verdict' => $verdict, 'issues' => $issues, 'error' => $error];
    }
}
