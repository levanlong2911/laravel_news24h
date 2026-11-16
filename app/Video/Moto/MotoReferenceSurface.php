<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoReferenceSurface
{
    public const VERSION = 'moto-reference-surface-v3';

    public const FINISHED = 'finished';

    public const UNFINISHED = 'unfinished';

    public const DIGITAL = 'digital';

    public const UNKNOWN = 'needs_check';

    /** @var string the name a shot run pinned for the rule of its surfaces before code stopped reading the words of a material; kept so such a run still reads */
    public const RULE_MATERIAL = 'material-v1';

    /** @var list<string> the group states in which a coloured component carries its palette colour and finish */
    private const COLOURED = ['paint', 'powder_coat', 'final'];

    /**
     * @param  array<string, mixed>  $part  the part of the pinned design
     * @param  array<string, mixed>  $state  the build state of the design the reference shows
     * @param  array<string, array<string, mixed>>  $palette  the palette of the design by colour id
     * @param  bool  $beingMade  the shot shows the part being made, or the state lists it in progress
     * @param  array<string, string>  $groups  the surface group of each part kind the code maps
     * @param  array<string, string>  $labels  the words of an unfinished, digital or unknown finish
     * @return list<array{element: string, material: string, design_finish: string, color_id: ?string, status: string, text: string, basis: list<array<string, mixed>>}> every surface of the part as it stands at the state, from the values of the design alone: finished with its design finish, unfinished, a digital model, or needs_check when no group or state gives its finish; no word of a material, a finish or a sentence is read
     */
    public static function components(array $part, array $state, array $palette, bool $beingMade, array $groups, array $labels): array
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

            $rawMetal = $groupStatus === 'raw_metal';
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
                $beingMade => $unfinished($rawMetal ? $labels['raw_metal'] : $labels['being_made'], [
                    $inProgress === null ? ['path' => 'shot.presentation', 'value' => 'being_made'] : ['path' => "design.build_states.{$stateId}.in_progress", 'quote' => (string) $inProgress['description']],
                    ...($groupStatus === null ? [] : [['path' => "design.build_states.{$stateId}.surfaces.{$group}", 'value' => $groupStatus]]),
                ]),
                ($state['kind'] ?? null) === 'finished' && ((array) ($state['exceptions'] ?? [])) === [] => $finished([['path' => "design.build_states.{$stateId}.kind", 'value' => 'finished']]),
                $groupStatus === 'final' => $finished([['path' => "design.build_states.{$stateId}.surfaces.{$group}", 'value' => 'final']]),
                $rawMetal => $unfinished($labels['raw_metal'], [['path' => "design.build_states.{$stateId}.surfaces.{$group}", 'value' => 'raw_metal']]),
                $groupStatus === 'primer' && $colour !== null => $unfinished($labels['primer'], [['path' => "design.build_states.{$stateId}.surfaces.{$group}", 'value' => 'primer']]),
                in_array($groupStatus, self::COLOURED, true) && $colour !== null => $finished([['path' => "design.build_states.{$stateId}.surfaces.{$group}", 'value' => $groupStatus]]),
                default => null,
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
}
