<?php

declare(strict_types=1);

namespace App\Video\Render\Video;

use App\Video\Screenplay\SceneBeats;

final class FilmSegmentPrompt
{
    public const VERSION = 'film-segment-prompt-v4';

    public const ROLE_IDENTITY = 'identity';

    public const ROLE_DESIGN_VIEW = 'design_view';

    public const ROLE_ENVIRONMENT = 'environment';

    public const ROLE_KEYFRAME = 'keyframe';

    /**
     * @param  list<array<string, mixed>>  $shots
     * @param  array{name: string, finish: array<string, string>, segment: int, segments: int, aspect_ratio: string,
     *               junction_in: ?string, junction_out: ?string}  $context
     * @param  list<array{role: string, label: string}>  $references
     */
    public static function compile(array $shots, array $context, array $references): string
    {
        $seconds = static fn (int $ms): string => number_format($ms / 1000, 1);
        $last = $shots === [] ? 0 : (int) $shots[count($shots) - 1]['end_ms'];
        $cuts = array_map(static fn (array $shot): string => $seconds((int) $shot['start_ms']), array_slice($shots, 1));
        $finished = array_filter($shots, static fn (array $shot): bool => (bool) ($shot['source']['finished'] ?? false));
        $blocks = [];

        $blocks[] = sprintf(
            'One %s-second vertical film segment of %d scene%s%s. This is part %d of %d of a short film about the superyacht %s.',
            $seconds($last),
            count($shots),
            count($shots) === 1 ? '' : 's',
            $cuts === [] ? '' : ' with hard cuts at '.implode(', ', $cuts).' seconds',
            $context['segment'],
            $context['segments'],
            $context['name'],
        );

        if ($references !== []) {
            $lines = [];
            $frameNumbers = [];

            foreach (array_values($references) as $index => $reference) {
                $lines[] = ($index + 1).'. '.self::roleLine($reference, $context['name']);

                if ($reference['role'] === self::ROLE_KEYFRAME) {
                    $frameNumbers[] = $index + 1;
                }
            }

            $blocks[] = "REFERENCE IMAGES, in the order given:\n".implode("\n", $lines)."\n".($frameNumbers === []
                ? 'Use them for design, structure and place only. None of them is a first frame.'
                : 'Images '.implode(', ', $frameNumbers).' are the approved opening frames of their scenes, in order: each of those scenes opens on its own frame.'
                    .(count($frameNumbers) < count($references) ? ' The other images are design references only and never a first frame.' : ''));
        }

        if ($finished !== [] && array_filter($context['finish'], static fn (string $value): bool => $value !== '') !== []) {
            $blocks[] = 'FINISHED APPEARANCE, only in the shots marked finished: '
                .implode(' ', array_filter($context['finish'], static fn (string $value): bool => $value !== ''));
        }

        $timeline = [];
        $frames = [];

        foreach (array_values($references) as $index => $reference) {
            if ($reference['role'] === self::ROLE_KEYFRAME) {
                foreach ((array) ($reference['used_by'] ?? []) as $order) {
                    $frames[(int) $order] = $index + 1;
                }
            }
        }

        foreach ($shots as $shot) {
            $state = self::stateLine($shot['source']);
            $result = trim((string) ($shot['source']['visible_result'] ?? ''));
            $frame = $frames[(int) ($shot['order'] ?? 0)] ?? null;
            $visual = trim((string) ($shot['visual'] ?? ''));
            $timeline[] = sprintf(
                "%s–%s s · %s.%s\n  Action (must happen): %s%s%s\n  Camera: %s.%s",
                $seconds((int) $shot['start_ms']),
                $seconds((int) $shot['end_ms']),
                $shot['location_name'],
                $frame === null ? '' : ' Opens on reference image '.$frame.'.',
                self::sentence((string) $shot['source']['action']),
                $result === '' ? '' : ' Visible result: '.self::sentence($result),
                $visual === '' ? '' : "\n  Shown as: ".self::sentence($visual),
                self::cameraLine($shot['camera']),
                $state === '' ? '' : "\n  State: ".$state,
            );
        }

        $shownAs = array_filter($shots, static fn (array $shot): bool => trim((string) ($shot['visual'] ?? '')) !== '') !== [];
        $blocks[] = "TIMELINE\nEach scene shows its action in the time it has."
            .($shownAs ? ' Where a "Shown as" line differs from the action, the action wins.' : '')
            ."\n".implode("\n", $timeline);

        $rules = [
            sprintf('Vertical %s, photorealistic, natural colour, stabilised camera.', $context['aspect_ratio']),
            'Each scene shows one readable moment and holds for its full time; the cuts fall exactly on the marks.',
            'Keep the vessel identical to the reference design in every shot.',
            'Sound: on-location ambience and the sounds of the actions only. No music, no dialogue, no voice-over, no on-screen text.',
        ];

        if ($context['junction_in'] !== null) {
            $rules[] = 'The segment opens continuing from the previous part: '.$context['junction_in'];
        }

        if ($context['junction_out'] !== null) {
            $rules[] = 'The segment ends leading into the next part: '.$context['junction_out'];
        }

        $blocks[] = "ALL SHOTS\n".implode("\n", $rules);

        return implode("\n\n", $blocks);
    }

    /** @param array{role: string, label: string} $reference */
    private static function roleLine(array $reference, string $name): string
    {
        return match ($reference['role']) {
            self::ROLE_IDENTITY => 'The design of '.$name.' as bare structure: take only its shape from it and keep its hull, superstructure, bands, openings and stern exactly. Never put its bare surface on a shot marked finished.',
            self::ROLE_DESIGN_VIEW => $reference['label'].' of the same design, bare structure: its shape from this viewpoint only, never its bare surface on a shot marked finished.',
            self::ROLE_KEYFRAME => 'The approved opening frame of '.$reference['label'].': its place, composition, light and vessel state.',
            default => 'The place '.$reference['label'].'.',
        };
    }

    /** @param string|array{position: string, elevation: string, framing: string, subject_side: ?string, movement: string} $camera */
    private static function cameraLine(string|array $camera): string
    {
        if (is_string($camera)) {
            return rtrim(trim($camera), '.');
        }

        $line = rtrim($camera['framing'], '.').'; '.rtrim($camera['position'], '.').'; '.rtrim($camera['elevation'], '.').'; '
            .($camera['movement'] === 'static' ? 'locked off' : str_replace('_', ' ', $camera['movement']));

        return $camera['subject_side'] === null ? $line : $line.'; facing the '.$camera['subject_side'].' side';
    }

    private static function sentence(string $text): string
    {
        return rtrim(trim($text), '.').'.';
    }

    /** @param array<string, mixed> $source */
    private static function stateLine(array $source): string
    {
        $parts = [];
        $progress = (array) ($source['progress'] ?? []);
        $room = (array) ($source['room_state'] ?? []);
        $start = is_string($progress['start'] ?? null) && $progress['start'] !== '' ? $progress['start'] : null;
        $end = is_string($progress['end'] ?? null) && $progress['end'] !== '' ? $progress['end'] : null;
        [$beat, $beats] = array_pad((array) ($source['beat_position'] ?? []), 2, null);

        if ($start !== null && ($end === null || $start === $end)) {
            $parts[] = self::sentence($start);
        } elseif ($start !== null || $end !== null) {
            $parts[] = 'At the start of this scene: '.self::sentence((string) ($start ?? 'not stated'))
                .' By its end: '.self::sentence((string) ($end ?? 'not stated'))
                .($beat === null ? '' : ' This shot is beat '.$beat.' of '.$beats.'; it shows only what this action has made, nothing of later beats.');
        }

        $roomLine = static fn (?string $state): ?string => match ($state) {
            SceneBeats::SHELL_ONLY => 'bare structure with no interior finish',
            SceneBeats::IN_FIT_OUT => 'being fitted, with only what the state names installed',
            SceneBeats::FITTED => 'fitted and finished',
            default => null,
        };
        $roomStart = $roomLine($room['start'] ?? null);
        $roomEnd = $roomLine($room['end'] ?? null);

        if ($roomStart !== null && ($room['start'] ?? null) === ($room['end'] ?? null)) {
            $parts[] = 'The room is '.$roomStart.'.';
        } elseif ($roomStart !== null || $roomEnd !== null) {
            $parts[] = 'The room goes from '.($roomStart ?? 'not stated').' to '.($roomEnd ?? 'not stated')
                .' during the scene; show it only as far as this action takes it.';
        }

        $parts = array_values(array_filter($parts, static fn (?string $part): bool => $part !== null));

        if ($parts === []) {
            return '';
        }

        $parts[] = ($source['finished'] ?? false)
            ? 'Finished.'
            : 'Not finished: show only the structure and work this state names, with no paint, decking or finish beyond it.';

        return implode(' ', $parts);
    }
}
