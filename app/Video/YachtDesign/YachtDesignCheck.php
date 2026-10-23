<?php

declare(strict_types=1);

namespace App\Video\YachtDesign;

use App\Video\DesignDna\DnaCheck;

final class YachtDesignCheck
{
    public const FORMAT = 'phase3-design-v1';

    public const VERSION = 'yacht-design-check-v3';

    private const DECK_HEIGHT_TOLERANCE = 0.1;

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
     * @param  array{profile_margin_m: float|int, near_x: float|int, near_z_m: float|int}  $limits
     * @return array{version: string, format: string, verdict: string, issues: list<string>, warnings: list<string>, error: ?string}
     */
    public static function evaluate(?array $response, string $projectId, array $dna, array $sourceIdentity, array $forbidden, array $schemaIssues, BuildStateText $text, array $limits): array
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

        [$landmarkIssues, $warnings] = self::landmarkGeometry($canonical, $limits);
        array_push($issues, ...$landmarkIssues);

        foreach ($schemaIssues as $issue) {
            $issues[] = "Schema: {$issue}";
        }

        return self::result($issues === [] ? self::PASS : self::FAIL, $issues, null, $warnings);
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
    private static function result(string $verdict, array $issues, ?string $error, array $warnings = []): array
    {
        return ['version' => self::VERSION, 'format' => self::FORMAT, 'verdict' => $verdict, 'issues' => $issues, 'warnings' => $warnings, 'error' => $error];
    }
}
