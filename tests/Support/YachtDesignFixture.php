<?php

namespace Tests\Support;

use App\Video\YachtDesign\BuildStateText;
use App\Video\YachtDesign\YachtDesignCheck;

final class YachtDesignFixture
{
    /** @return array<string, mixed> */
    public static function envelope(string $projectId, string $promptVersion = 'spec-v6.3-phase-3@rev3'): array
    {
        $words = static fn (int $count, string $word): string => implode(' ', array_fill(0, $count, $word));
        $rooms = array_map(static fn (int $n): string => sprintf('INT%02d', $n), range(1, 10));
        $colors = [
            ['role' => 'hull', 'name' => 'Basalt Graphite', 'hex' => '#2B2E33'],
            ['role' => 'superstructure', 'name' => 'Salt White', 'hex' => '#EEECE6'],
            ['role' => 'accent', 'name' => 'Oxidized Bronze', 'hex' => '#6E5A43'],
        ];
        $state = static fn (string $id, string $kind, array $present, array $absent, array $surfaces): array => [
            'id' => $id, 'kind' => $kind, 'present_parts' => $present, 'absent_parts' => $absent, 'surfaces_by_part' => $surfaces, 'compiled_text' => '',
        ];

        return [
            'format' => YachtDesignCheck::FORMAT,
            'phase' => '3',
            'prompt_version' => $promptVersion,
            'project_id' => $projectId,
            'status' => 'ok',
            'data' => [
                'yacht_bible' => [
                    'name' => 'Arclight',
                    'specs' => [
                        'loa' => ['value' => 148, 'unit' => 'm', 'basis' => 'CONCEPT_ASSUMPTION'],
                        'beam' => ['value' => 21, 'unit' => 'm', 'basis' => 'CONCEPT_ASSUMPTION'],
                        'gross_tonnage' => ['value' => 7200, 'unit' => 'GT', 'basis' => 'CONCEPT_ASSUMPTION'],
                        'decks' => ['value' => 8, 'unit' => 'decks', 'basis' => 'CONCEPT_ASSUMPTION'],
                        'guests' => ['value' => 24, 'unit' => 'persons', 'basis' => 'CONCEPT_ASSUMPTION'],
                        'crew' => ['value' => 72, 'unit' => 'persons', 'basis' => 'CONCEPT_ASSUMPTION'],
                        'justifications' => [],
                    ],
                    'decks' => [['name' => 'Main', 'layout' => 'salon forward, dining aft']],
                    'colors' => $colors,
                    'innovations' => [
                        ['id' => 'N2', 'name' => 'Ripple Shell', 'position' => 'Ribs cover the windward slope.', 'geometry' => 'Ribs turn across the crest.', 'visible' => 'Readable in profile.'],
                        ['id' => 'N1', 'name' => 'Canyon Heliostat.', 'position' => 'A slot sits on the centreline.', 'geometry' => 'The slot is narrow.', 'visible' => 'Visible from above.'],
                        ['id' => 'N3', 'name' => 'Lee Pocket', 'position' => 'A pool sits in the scoop.', 'geometry' => 'The shell shelters it.', 'visible' => 'Visible from the stern.'],
                    ],
                    'exterior' => [
                        'silhouette' => 'One rising hill.',
                        'bow' => 'A raked stem.',
                        'hull' => 'A steel hull.',
                        'superstructure' => 'Decks shorten aft.',
                        'glazing' => 'Separated windows.',
                        'mast_and_radar' => 'No freestanding mast.',
                        'helideck' => 'No helideck.',
                        'stern' => 'A fixed stern toe.',
                        'water_access' => 'Steps beside a slipway.',
                    ],
                    'must_avoid_resolution' => ['The outer silhouette is one hill.', 'The cinema lounge stays below.', 'The owner suite sits under the crest.', 'Cabinets are not shown.', 'No chrome ornament.'],
                    'exterior_zones' => [['id' => 'EXT01', 'text' => 'foredeck']],
                    'rooms' => array_map(static fn (string $id): array => ['id' => $id, 'deck' => 'Main', 'materials' => ['oak'], 'palette' => ['#E9E3D8']], $rooms),
                ],
                'anchors' => [
                    'yacht' => $words(70, 'hull'),
                    'geometry' => $words(60, 'arch'),
                    'finished_surface' => $words(25, 'gloss'),
                    'surface_states' => array_combine(
                        array_map(static fn (int $n): string => sprintf('BS%02d', $n), range(6, 12)),
                        array_fill(0, 7, 'bare welded steel with primer'),
                    ),
                    'exterior' => ['EXT01' => $words(40, 'deck')],
                    'interior' => array_combine($rooms, array_fill(0, 10, $words(40, 'room'))),
                ],
                'build_states' => array_combine(
                    array_map(static fn (int $n): string => sprintf('BS%02d', $n), range(1, 12)),
                    array_fill(0, 12, 'the yacht in a stated build state'),
                ),
                'canonical' => [
                    'revision' => 0,
                    'revision_kind' => 'draft',
                    'hash' => '',
                    'name' => 'Arclight',
                    'dimensions' => [
                        'loa' => ['value' => 148, 'unit' => 'm', 'basis' => 'CONCEPT_ASSUMPTION'],
                        'lwl' => ['value' => 140, 'unit' => 'm', 'basis' => 'CONCEPT_ASSUMPTION'],
                        'beam' => ['value' => 21, 'unit' => 'm', 'basis' => 'CONCEPT_ASSUMPTION'],
                        'draft' => ['value' => 5.6, 'unit' => 'm', 'basis' => 'CONCEPT_ASSUMPTION'],
                        'crest_height' => ['value' => 13, 'unit' => 'm', 'basis' => 'CONCEPT_ASSUMPTION'],
                        'bow_freeboard' => ['value' => 6, 'unit' => 'm', 'basis' => 'CONCEPT_ASSUMPTION'],
                    ],
                    'decks' => array_map(static fn (int $n): array => [
                        'name' => "Deck {$n}", 'floor_z_m' => 3.5 * ($n - 2), 'ceiling_z_m' => 3.5 * ($n - 1), 'height_m' => 3.5, 'setback_m' => 0, 'start_x' => 0.05, 'end_x' => 0.9,
                    ], range(1, 8)),
                    'profile_curve' => array_map(static fn (int $i): array => [$i / 20, 3 + $i * 0.5], range(0, 20)),
                    'plan_outline' => array_map(static fn (int $i): array => [$i / 20, 2 + $i * 0.3], range(0, 20)),
                    'deck_outlines' => [],
                    'coordinate_system' => ['x' => 'Fraction of LOA from the bow.'],
                    'landmarks' => [
                        'helideck' => ['x' => 0.2, 'z' => 4.5],
                        'crest' => ['x' => 0.7, 'z' => 10],
                    ],
                    'surfaces' => ['finished' => 'final paint and glass', 'glazing' => 'Smoke-grey glazing.', 'metal_finishes' => 'Brushed frames.', 'colors' => $colors],
                    'build_states' => [
                        $state('BS05', 'scale_model', ['hull_shell'], ['superstructure'], ['hull_shell' => 'scale_model_white']),
                        $state('BS06', 'build', ['keel_blocks'], ['hull_shell', 'superstructure'], ['keel_blocks' => 'steel_primer']),
                        $state('BS07', 'build', ['hull_shell', 'main_deck'], ['superstructure'], ['hull_shell' => 'steel_primer']),
                        $state('BS08', 'build', ['hull_shell', 'main_deck', 'superstructure', 'scaffolding_on_hull'], ['glazing'], ['superstructure' => 'raw_aluminium']),
                        $state('BS09', 'build', ['hull_shell', 'superstructure', 'glazing'], [], ['glazing' => 'protective_film']),
                        $state('BS10', 'build', ['hull_shell', 'superstructure', 'glazing'], [], ['hull_shell' => 'filler_primer']),
                        $state('BS11', 'finished', ['hull_shell', 'superstructure', 'glazing'], [], ['hull_shell' => 'final_paint']),
                        $state('BS12', 'finished', ['hull_shell', 'superstructure', 'glazing'], [], ['hull_shell' => 'final_paint', 'glazing' => 'final_glass']),
                    ],
                    'model_sheet' => ['views' => [['id' => 'MS1', 'view' => 'port side profile'], ['id' => 'MS8', 'view' => 'close-up of N1, showing the slot']]],
                    'operational_limits' => [['feature' => 'hull_doors_open', 'allowed_when' => ['at_anchor_only' => true, 'max_speed_kn' => 0]]],
                    'rooms' => array_map(static fn (string $id): array => ['id' => $id, 'deck' => 'Main', 'window_type' => 'floor-to-ceiling glass'], $rooms),
                ],
            ],
            'flags' => [],
            'notes' => '',
        ];
    }

    /** @return array{profile_margin_m: float, near_x: float, near_z_m: float} */
    public static function limits(): array
    {
        return ['profile_margin_m' => 0.2, 'near_x' => 0.005, 'near_z_m' => 0.3];
    }

    public static function text(): BuildStateText
    {
        $phrases = static fn (array $keys): array => array_combine($keys, array_map(static fn (string $key): string => str_replace('_', ' ', $key), $keys));

        return new BuildStateText([
            'parts' => $phrases(['keel_blocks', 'hull_shell', 'main_deck', 'superstructure', 'glazing', 'hull_doors', 'observation_blister', 'helideck', 'mast', 'stern_platform', 'terraces', 'scaffolding_on_hull']),
            'surfaces' => $phrases(['scale_model_white', 'bare_steel', 'steel_primer', 'raw_aluminium', 'protective_film', 'filler_primer', 'final_paint', 'final_glass', 'teak', 'other_final']),
        ]);
    }

    /** @return array<string, mixed> */
    public static function dna(): array
    {
        return ['innovations' => [['id' => 'N1'], ['id' => 'N2'], ['id' => 'N3']]];
    }
}
