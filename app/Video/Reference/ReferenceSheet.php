<?php

declare(strict_types=1);

namespace App\Video\Reference;

use App\Video\Screenplay\DesignText;
use App\Video\Screenplay\VesselDesign;

final class ReferenceSheet
{
    public const VIEW_OPTION = 'reference_sheet';

    public const MODE = 'contact_sheet';

    public const LAYOUT_VERSION = 'reference-sheet-layout-v2';

    public const DERIVATION_VERSION = 'reference-sheet-v1';

    public const STATE_AS_ANCHOR = 'as_anchor';

    public const STATE_FINISHED = 'finished';

    /** @var list<string> */
    public const STATES = [self::STATE_AS_ANCHOR, self::STATE_FINISHED];

    /** @var array<string, int> */
    public const SLOTS = [self::STATE_AS_ANCHOR => 20, self::STATE_FINISHED => 21];

    public const MAX_PROMPT_CHARS = 32000;

    /** @var list<string> */
    public const PANELS = ['port_side', 'starboard_side', 'bow_front', 'stern_rear', 'top_down', self::STERN_THREE_QUARTER_STARBOARD];

    public const STERN_THREE_QUARTER_STARBOARD = 'stern_three_quarter_starboard';

    /** @var list<string> */
    private const POSITIONS = ['top left', 'top centre', 'top right', 'bottom left', 'bottom centre', 'bottom right'];

    /** @var list<string> */
    private const FINISH_FIELDS = ['hull', 'superstructure', 'glazing', 'materials'];

    public static function stateLabel(string $state): string
    {
        return $state === self::STATE_FINISHED ? 'Hoàn thiện theo thiết kế' : 'Giữ nguyên anchor';
    }

    /** @return list<array{position: string, view: string, label: string}> */
    public static function legend(): array
    {
        $legend = [];

        foreach (self::PANELS as $index => $value) {
            $legend[] = ['position' => self::POSITIONS[$index], 'view' => $value, 'label' => self::panel($value)['display']];
        }

        return $legend;
    }

    /**
     * @param  array<string, mixed>  $design
     * @return array{appearance: array<string, string>, equipment: list<string>}|null
     */
    public static function finish(array $design): ?array
    {
        $identity = $design['canonical_design']['appearance_identity'] ?? null;

        if (! is_array($identity) || ($identity['status'] ?? null) !== 'locked') {
            return null;
        }

        $appearance = [];

        foreach (self::FINISH_FIELDS as $field) {
            $text = is_string($identity[$field] ?? null) ? trim($identity[$field]) : '';

            if ($text === '') {
                return null;
            }

            $appearance[$field] = $text;
        }

        $equipment = [];
        $names = DesignText::names($design);

        foreach ((array) ($design['canonical_design']['permanent_secondary_geometry'] ?? []) as $item) {
            if (is_array($item) && ($item['form'] ?? null) === 'equipment' && is_string($item['element'] ?? null)) {
                $equipment[] = DesignText::readable(trim($item['element']).(is_string($item['position'] ?? null) && trim($item['position']) !== '' ? ': '.trim($item['position']) : ''), $names);
            }
        }

        return ['appearance' => $appearance, 'equipment' => $equipment];
    }

    /**
     * @param  array<string, mixed>  $design
     * @return array{0: ?string, 1: string}
     */
    public static function compile(array $design, string $state, ReferenceEnvironment $environment): array
    {
        if (! in_array($state, self::STATES, true)) {
            return [null, 'reference_sheet_state_unknown'];
        }

        $finish = $state === self::STATE_FINISHED ? self::finish($design) : null;

        if ($state === self::STATE_FINISHED && $finish === null) {
            return [null, 'reference_sheet_finish_incomplete'];
        }

        $panels = [];

        foreach (self::PANELS as $index => $value) {
            $panel = self::panel($value);
            $panels[] = 'Panel '.($index + 1).', '.self::POSITIONS[$index].' — '.$panel['label'].': '.$panel['camera'];
        }

        $blocks = [
            'Edit the supplied image into one reference contact sheet of the same vessel: a grid of three columns and two rows '
                .'of six equal panels separated by thin plain gutters. Each panel shows the whole vessel from one camera, nothing '
                .'cropped; panels never overlap or merge. No text, labels, numbers, captions or borders other than the gutters. '
                .'It is the same vessel in every panel, not a new design.',
            "PANELS (in each camera instruction, \"frame\" means that panel):\n".implode("\n", $panels),
            'The port and starboard panels are two separate true views of two different sides; never mirror one side to make '
                .'the other. All six panels share the same light, background and surface state.',
            IdentityPreservationPrompt::text(),
            "VIEW GEOMETRY (the approved design, for all six panels):\n".self::designText($design),
            'CONSISTENCY: The vessel is identical in all six panels: the same hull, number of decks, proportions, bands, '
                .'openings, stern, stairs and pools. Each panel shows only what is visible from its camera; nothing is moved, '
                .'mirrored or added to expose a hidden part.',
        ];

        if ($environment->override() !== '') {
            $blocks[] = $environment->override().' This applies to every panel.';
        }

        $blocks[] = $finish === null ? IdentityPreservationPrompt::referenceState() : self::finishedState($finish);
        $prompt = implode("\n\n", $blocks);

        return mb_strlen($prompt) > self::MAX_PROMPT_CHARS
            ? [null, 'reference_sheet_prompt_too_long']
            : [$prompt, 'ok'];
    }

    /** @param array{appearance: array<string, string>, equipment: list<string>} $finish */
    private static function finishedState(array $finish): string
    {
        $lines = [
            'LIGHTING: Soft, large-source, neutral light with controlled contrast and even exposure that reveals planes, edges, '
                .'setbacks, recesses, overhangs and openings.',
            'SUBJECT STATE: The finished vessel exactly as designed. Apply only these surfaces and fitted items; every geometry '
                .'fact above stays unchanged.',
            'Hull: '.$finish['appearance']['hull'],
            'Superstructure: '.$finish['appearance']['superstructure'],
            'Glazing: '.$finish['appearance']['glazing'],
            'Materials: '.$finish['appearance']['materials'],
        ];

        $lines[] = $finish['equipment'] === []
            ? 'No equipment is added beyond the geometry above.'
            : "Fitted equipment, each in its designed place and nothing else:\n- ".implode("\n- ", $finish['equipment']);
        $lines[] = 'No people, no loose items, no text. Do not add new elements. Do not change anything else.';

        return implode("\n", $lines);
    }

    /**
     * @return array{label: string, display: string, camera: string, frame: array{near_end: ?string, side: ?string, aspects: list<string>}, proof_views: list<string>}
     */
    private static function panel(string $value): array
    {
        if ($value === self::STERN_THREE_QUARTER_STARBOARD) {
            return [
                'label' => 'Stern Three-quarter Starboard',
                'display' => 'Góc 3/4 phía sau (mạn phải)',
                'camera' => 'The camera sits about thirty-five degrees off the stern centreline toward the starboard side, '
                    .'its line of sight about fifteen degrees below horizontal, on a moderate telephoto lens at a distance great '
                    .'enough to keep optical distortion low. The stern is nearer the camera than the bow; the stern face and the '
                    .'starboard flank are both fully visible and the flank still reads as a vertical face. '
                    .ReferenceView::frameDirection('starboard').' The frame holds the whole vessel from stern to stem. '
                    .ReferenceView::hiddenEnd('stern', 'starboard'),
                'frame' => ['near_end' => 'stern', 'side' => 'starboard', 'aspects' => ['aft', 'starboard']],
                'proof_views' => [self::STERN_THREE_QUARTER_STARBOARD],
            ];
        }

        $view = ReferenceView::from($value);

        return [
            'label' => $view->label(),
            'display' => $view->displayLabel(),
            'camera' => self::panelCamera($view->cameraBlock()),
            'frame' => $view->frame(),
            'proof_views' => $view->proofViews(),
        ];
    }

    private static function panelCamera(string $block): string
    {
        $text = preg_replace('/^REFERENCE VIEW OVERRIDE:\s*Replace any previous CAMERA \/ VIEW instruction with the following\.\s*/', '', trim($block)) ?? $block;
        $text = preg_replace('/^Keep all canonical identity, topology and proportions unchanged\.\s*/', '', $text) ?? $text;

        return trim($text);
    }

    /** @param array<string, mixed> $design */
    private static function designText(array $design): string
    {
        $names = DesignText::names($design);
        $extracts = [];

        foreach (self::PANELS as $value) {
            $panel = self::panel($value);
            $extracts[] = VesselDesign::referenceDesign($design, $value, $panel['frame'], $panel['proof_views']);
        }

        $first = $extracts[0];
        $identity = (array) ($first['identity'] ?? []);
        $lines = [sprintf(
            'VESSEL: %s, length %s m, beam %s m, length-to-beam ratio %s.',
            (string) ($identity['name'] ?? ''),
            (string) ($identity['length_m'] ?? ''),
            (string) ($identity['beam_m'] ?? ''),
            (string) ($identity['length_beam_ratio'] ?? ''),
        )];
        $seen = [];

        foreach ((array) ($first['common_geometry'] ?? []) as $key => $node) {
            $lines = [...$lines, ...DesignText::section((string) $key, $node, $names, $seen)];
        }

        $viewGeometry = [];

        foreach ($extracts as $extract) {
            foreach ((array) ($extract['view_geometry'] ?? []) as $key => $node) {
                $viewGeometry[$key][] = $node;
            }
        }

        foreach ($viewGeometry as $key => $nodes) {
            $merged = array_is_list((array) $nodes[0]) ? array_merge(...array_map(static fn (mixed $node): array => (array) $node, $nodes)) : array_replace(...array_map(static fn (mixed $node): array => (array) $node, $nodes));
            $lines = [...$lines, ...DesignText::section((string) $key, $merged, $names, $seen)];
        }

        foreach ((array) ($first['constraints'] ?? []) as $key => $node) {
            $lines = [...$lines, ...DesignText::section((string) $key, $node, $names, $seen)];
        }

        return DesignText::readable(implode("\n", $lines), $names);
    }
}
