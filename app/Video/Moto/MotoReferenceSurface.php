<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoReferenceSurface
{
    public const VERSION = 'moto-reference-surface-v2';

    public const FINISHED = 'finished';

    public const UNFINISHED = 'unfinished';

    public const DIGITAL = 'digital';

    public const UNKNOWN = 'needs_check';

    /** @var string the rule of a run pinned before the words of a surface looked at its material: every surface reads as metal, and a part being made with no group status reads as raw metal */
    public const RULE_ANY_MATERIAL = 'any-material';

    /** @var string the current rule: raw metal is said only of a material known to be bare metal, whose group the build state gives as raw metal; a mixed, plated or unknown material, or no such evidence, keeps the material and leaves its finish open */
    public const RULE_MATERIAL = 'material-v1';

    /** @var list<string> the group states in which a coloured component carries its palette colour and finish */
    private const COLOURED = ['paint', 'powder_coat', 'final'];

    /** @var list<string> the words that name a bare metal; part of RULE_MATERIAL, so a change to them needs a new rule. Chrome is a plating, not one of them */
    private const METALS = ['aluminium', 'aluminum', 'steel', 'titanium', 'iron', 'brass', 'bronze', 'copper', 'magnesium', 'nickel', 'zinc', 'alloy', 'metal'];

    /** @var list<string> the other words a bare metal may be named with: how it is worked and its stock form; part of RULE_MATERIAL */
    private const METAL_WORDS = ['and', 'stainless', 'mild', 'cast', 'machined', 'formed', 'welded', 'forged', 'billet', 'extruded', 'stamped', 'pressed', 'rolled', 'spun', 'bent', 'drawn', 'hammered', 'planished', 'sheet', 'tube', 'tubing', 'bar', 'rod'];

    /**
     * @param  array<string, mixed>  $part  the part of the pinned design
     * @param  array<string, mixed>  $state  the build state of the design the reference shows
     * @param  array<string, array<string, mixed>>  $palette  the palette of the design by colour id
     * @param  bool  $beingMade  the shot shows the part being made, or the state lists it in progress
     * @param  list<array{path: string, text: string}>  $texts  the texts that may stand as evidence of a finish, all of them true at the first frame: the state's own surface text, the shot's first frame, its continuity at the start, what it carries from the shot before and what its needs ask to show; never what the shot goes on to do
     * @param  array<string, string>  $groups  the surface group of each part kind the code maps
     * @param  array<string, string>  $labels  the words of an unfinished, digital or unknown finish
     * @param  string  $rule  RULE_MATERIAL, or the rule a run was pinned with, so a stored plan keeps its words
     * @return list<array{element: string, material: string, design_finish: string, color_id: ?string, status: string, text: string, basis: list<array<string, mixed>>}> every surface of the part as it stands at the state: finished with its design finish, unfinished, with the design finish when a text names it on raw metal, a digital model, or needs_check when no group, state or shot gives its finish
     */
    public static function components(array $part, array $state, array $palette, bool $beingMade, array $texts, array $groups, array $labels, string $rule = self::RULE_MATERIAL): array
    {
        $id = (string) $part['id'];
        $stateId = (string) $state['id'];
        $group = $groups[(string) ($part['kind'] ?? '')] ?? null;
        $groupStatus = $group === null ? null : (((array) ($state['surfaces'] ?? []))[$group] ?? null);
        $inProgress = collect((array) ($state['in_progress'] ?? []))->firstWhere('part', $id);
        $components = [];

        foreach (array_values((array) ($part['surfaces'] ?? [])) as $index => $surface) {
            $element = (string) $surface['element'];
            $material = (string) $surface['material'];
            $finish = (string) $surface['finish'];
            $colour = is_string($surface['color_id'] ?? null) ? ($palette[$surface['color_id']] ?? null) : null;
            $own = ['path' => "design.parts.{$id}.surfaces[{$index}]", 'value' => $surface];
            $finished = static fn (array $basis): array => [
                'status' => self::FINISHED,
                'text' => "{$element}: {$material}".($colour === null ? ", {$finish}" : " in {$colour['name']} ({$colour['hex']}), {$finish}"),
                'basis' => [$own, ...$basis],
            ];
            $unfinished = static fn (string $words, array $basis): array => [
                'status' => self::UNFINISHED,
                'text' => "{$element}: {$material}, {$words}",
                'basis' => [$own, ...$basis],
            ];

            $byMaterial = $rule === self::RULE_MATERIAL;
            $rawMetal = $groupStatus === 'raw_metal' && (! $byMaterial || self::metal($material));
            $model = [['path' => "design.build_states.{$stateId}.kind", 'value' => 'model'], ['path' => "design.build_states.{$stateId}.model_medium", 'value' => $state['model_medium'] ?? null]];
            $resolved = match (true) {
                ($state['kind'] ?? null) === 'model' && ($state['model_medium'] ?? null) === 'digital' => [
                    'status' => self::DIGITAL,
                    'text' => "{$element}: {$labels['digital']}",
                    'basis' => $model,
                ],
                ($state['kind'] ?? null) === 'model' => [
                    'status' => self::UNKNOWN,
                    'text' => "{$element}: {$labels['physical_model']}",
                    'basis' => [$own, ...$model],
                ],
                $beingMade => $unfinished($rawMetal || (! $byMaterial && $groupStatus === null) ? $labels['raw_metal'] : $labels['being_made'], [
                    $inProgress === null ? ['path' => 'shot.presentation', 'value' => 'being_made'] : ['path' => "design.build_states.{$stateId}.in_progress", 'quote' => (string) $inProgress['description']],
                    ...($groupStatus === null ? [] : [['path' => "design.build_states.{$stateId}.surfaces.{$group}", 'value' => $groupStatus]]),
                ]),
                ($state['kind'] ?? null) === 'finished' && ((array) ($state['exceptions'] ?? [])) === [] => $finished([['path' => "design.build_states.{$stateId}.kind", 'value' => 'finished']]),
                $groupStatus === 'final' => $finished([['path' => "design.build_states.{$stateId}.surfaces.{$group}", 'value' => 'final']]),
                $rawMetal => ($named = self::named($element, $finish, $texts)) === null
                    ? $unfinished($labels['raw_metal'], [['path' => "design.build_states.{$stateId}.surfaces.{$group}", 'value' => 'raw_metal']])
                    : $unfinished(strtr((string) $labels['named_finish'], [':finish' => $finish]), [['path' => "design.build_states.{$stateId}.surfaces.{$group}", 'value' => 'raw_metal'], $named]),
                $groupStatus === 'primer' && $colour !== null => $unfinished($labels['primer'], [['path' => "design.build_states.{$stateId}.surfaces.{$group}", 'value' => 'primer']]),
                in_array($groupStatus, self::COLOURED, true) && $colour !== null => $finished([['path' => "design.build_states.{$stateId}.surfaces.{$group}", 'value' => $groupStatus]]),
                default => ($named = self::named($element, $finish, $texts)) === null ? null : $finished([$named]),
            };

            $resolved ??= [
                'status' => self::UNKNOWN,
                'text' => "{$element}: {$material}, {$labels['unknown']}",
                'basis' => [$own, ...($groupStatus === null ? [] : [['path' => "design.build_states.{$stateId}.surfaces.{$group}", 'value' => $groupStatus]])],
            ];

            $components[] = [
                'element' => $element,
                'material' => $material,
                'design_finish' => $finish,
                'color_id' => is_string($surface['color_id'] ?? null) ? $surface['color_id'] : null,
            ] + $resolved;
        }

        return $components;
    }

    /**
     * @param  list<array{status: string}>  $components
     * @return string colour when a component carries a finish, grey when every component is unfinished or digital
     */
    public static function regime(array $components): string
    {
        foreach ($components as $component) {
            if ($component['status'] === self::FINISHED) {
                return 'colour';
            }
        }

        return 'grey';
    }

    /** @return bool whether the material of the design is known to be bare metal: it names a metal and every other word is one a bare metal is named with. Any other word, as in a plating, a reinforcement, a mix with another material or a negation, leaves it unknown, and an unknown material is never called metal */
    private static function metal(string $material): bool
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($material), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_intersect($words, self::METALS) !== [] && array_diff($words, self::METALS, self::METAL_WORDS) === [];
    }

    /**
     * @param  list<array{path: string, text: string}>  $texts
     * @return ?array{path: string, quote: string} the clause of the texts that names the element together with its design finish, null when none does
     */
    private static function named(string $element, string $finish, array $texts): ?array
    {
        $nouns = array_values(array_filter(array_map(
            static fn (string $piece): string => rtrim(mb_strtolower((string) last(preg_split('/\s+/u', trim($piece)) ?: [''])), 's'),
            preg_split('/\s*(?:,|\band\b)\s*/u', $element) ?: [],
        ), static fn (string $noun): bool => mb_strlen($noun) >= 3));
        $words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($finish))) ?: [], static fn (string $word): bool => $word !== ''));

        if ($nouns === [] || $words === [] || mb_strlen(implode(' ', $words)) < 3) {
            return null;
        }

        $phrase = implode('\s+', array_map(static fn (string $word): string => preg_quote($word, '/'), $words));

        foreach ($texts as $text) {
            foreach (preg_split('/(?<=[.;])\s+|;\s*/u', (string) $text['text']) ?: [] as $clause) {
                $lower = mb_strtolower($clause);

                if (! preg_match('/(?<![\p{L}])'.$phrase.'(?![\p{L}])/u', $lower)) {
                    continue;
                }

                foreach ($nouns as $noun) {
                    if (preg_match('/(?<![\p{L}])'.preg_quote($noun, '/').'(?:s|es)?(?![\p{L}])/u', $lower)) {
                        return ['path' => $text['path'], 'quote' => trim($clause)];
                    }
                }
            }
        }

        return null;
    }
}
