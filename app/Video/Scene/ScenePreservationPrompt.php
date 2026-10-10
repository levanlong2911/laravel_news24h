<?php

namespace App\Video\Scene;

final class ScenePreservationPrompt
{
    public const VERSION = 'scene-preservation-v4';

    public const PRIORITY_VERSION = 'scene-preservation-v5';

    /** @var list<string> */
    public const PRIORITY_ROLES = ['continuity', 'design_reference'];

    public const MANIFEST_V3_VERSION = 'scene-preservation-v3';

    public const SINGLE_SOURCE_VERSION = 'scene-preservation-v2';

    public const LEGACY_VERSION = 'scene-preservation-v1';

    public const CONTINUATION = 'continuation_edit';

    public const HARD_CUT = 'hard_cut_edit';

    /** @return list<string> */
    public static function modes(): array
    {
        return [self::CONTINUATION, self::HARD_CUT];
    }

    /** @return list<string> */
    public static function versions(): array
    {
        return [self::LEGACY_VERSION, self::SINGLE_SOURCE_VERSION, self::MANIFEST_V3_VERSION, self::VERSION, self::PRIORITY_VERSION];
    }

    /** @param list<string> $roles */
    public static function versionFor(array $roles, string $version): string
    {
        return array_intersect($roles, self::PRIORITY_ROLES) === [] ? $version : self::PRIORITY_VERSION;
    }

    public static function forMode(string $mode, ?string $version = null): string
    {
        $version ??= self::SINGLE_SOURCE_VERSION;
        $manifest = [self::VERSION, self::MANIFEST_V3_VERSION, self::PRIORITY_VERSION];

        return match (true) {
            in_array($version, $manifest, true) && $mode === self::CONTINUATION => self::continuation(),
            in_array($version, $manifest, true) && $mode === self::HARD_CUT => self::hardCut(),
            $version === self::SINGLE_SOURCE_VERSION && $mode === self::CONTINUATION => self::continuation(),
            $version === self::SINGLE_SOURCE_VERSION && $mode === self::HARD_CUT => self::hardCut(),
            $version === self::LEGACY_VERSION && $mode === self::CONTINUATION => self::continuationV1(),
            $version === self::LEGACY_VERSION && $mode === self::HARD_CUT => self::hardCutV1(),
            default => throw new \InvalidArgumentException(
                'Unknown scene preservation mode or version: '.$mode.' / '.$version
            ),
        };
    }

    /**
     * @param  list<string>  $roles  vai tro theo dung thu tu manifest
     */
    public static function forManifest(string $mode, array $roles, ?string $version = null): string
    {
        $version ??= self::VERSION;
        $place = ($roles[0] ?? null) === 'environment';

        if (self::versionFor($roles, $version) === self::PRIORITY_VERSION) {
            return self::priorityManifest($mode, $roles, $place);
        }

        if (! $place && (! in_array($version, [self::VERSION, self::MANIFEST_V3_VERSION], true) || count($roles) < 2)) {
            return self::forMode($mode, $version);
        }

        $blocks = ['IMAGE 1 is the editable primary source. '.($place ? self::place() : self::forMode($mode, $version))];

        foreach (array_slice($roles, 1) as $index => $role) {
            $blocks[] = 'IMAGE '.($index + 2).' '.self::referenceLine($role, $version);
        }

        if (count($roles) > 1) {
            $blocks[] = 'Only IMAGE 1 is edited. The other images are read, never copied wholesale. '
                .'Never merge geometry from two references that disagree; where any reference disagrees '
                .'with IMAGE 1 about a part that is not being changed, IMAGE 1 wins.';
        }

        return implode("\n\n", $blocks);
    }

    /** @param list<string> $roles */
    private static function priorityManifest(string $mode, array $roles, bool $place): string
    {
        $continues = $mode === self::CONTINUATION;

        $blocks = [match (true) {
            $continues => 'IMAGE 1 is the previous frame of this shot and the image being edited. The render keeps its '
                .'camera position, framing, lighting and setting exactly. Every structure it shows keeps its shape, '
                .'proportion and placement, except the changes the description below states.',
            $place => 'IMAGE 1 is the permanent place this frame takes place in and the image being edited. The camera, '
                .'framing and composition of this frame follow the description below; keep the structure, layout and '
                .'fixed features of the place. The main subject of this film is not in this frame: add no vessel, hull '
                .'or model of one beyond what the description names.',
            default => 'IMAGE 1 is the approved design of the subject and the image being edited. The camera, framing and '
                .'composition of this frame follow the description below, not IMAGE 1. Whatever part of the subject '
                .'appears keeps the shape, proportion, topology and permanent openings IMAGE 1 shows; which parts exist '
                .'and how far the work has progressed follow the description.',
        }];

        foreach (array_slice($roles, 1) as $index => $role) {
            $blocks[] = 'IMAGE '.($index + 2).' '.match ($role) {
                'continuity' => 'is a continuity reference: the first frame of the previous shot in this same place, '
                    .'taken before that shot\'s action. Keep what carries on from it: the people this frame shows, their '
                    .'clothing, the props and the look of the place. Every change the description below states, including '
                    .'what the previous shot\'s action did and anyone arriving or leaving, takes effect. Never take its '
                    .'camera or framing.',
                'design_reference' => 'is the approved design of the subject that the drawings and models in this frame '
                    .'depict. Use it only for the lines of those drawings and models. The subject itself is not in this frame.',
                default => self::referenceLine($role, self::VERSION),
            };
        }

        $blocks[] = $continues
            ? 'Priorities: the camera and framing stay those of IMAGE 1. The description decides every change of state. '
                .'The approved design images decide the lines of every drawing and model of the subject, even where IMAGE 1 '
                .'or a continuity image shows them differently. Everything else stays as IMAGE 1 shows it. Only IMAGE 1 is '
                .'edited; the other images are read, never copied wholesale.'
            : 'Priorities: the description decides the camera, the composition and every change of state. IMAGE 1 decides '
                .'the permanent structure of the '.($place ? 'place' : 'subject').' it shows. The approved design images '
                .'decide the lines of every drawing and model of the subject, even where IMAGE 1 or a continuity image shows '
                .'them differently. A continuity image keeps only what carries on and never decides the camera. Only IMAGE 1 '
                .'is edited; the other images are read, never copied wholesale.';

        return implode("\n\n", $blocks);
    }

    private static function referenceLine(string $role, string $version): string
    {
        if ($role === 'environment' && $version === self::VERSION) {
            return 'is an environment reference only: it shows the permanent place this frame takes place in, '
                .'in a neutral state. Take the structure, layout and fixed features of the place from it. The state '
                .'of the place in this frame, such as water, gates and doors, fit-out, furniture, props, people and '
                .'light, follows the description below, not this reference. Do not take the subject\'s geometry from it.';
        }

        return match ($role) {
            'identity' => 'is an identity reference only: it shows the same subject from another '
                .'viewpoint so its proportions and permanent features stay right. Do not take its '
                .'camera, framing, lighting or setting.',
            'environment' => 'is an environment reference only: it shows the setting this frame takes '
                .'place in. Take the place, the light and the surrounding materials from it. Do not '
                .'take the subject\'s geometry from it.',
            'space_geometry' => 'is the confirmed geometry source for the space of the subject this frame '
                .'shows: read the permanent structure of that space from it. It shows the design, not this '
                .'moment: never take its build state, its completion, its camera, its light or its setting.',
            default => 'is a supporting geometry reference only: use it to read structure that IMAGE 1 '
                .'shows unclearly. Do not take its camera or setting.',
        };
    }

    /**
     * Khoa hinh hoc theo TUNG CAU KIEN, khong theo silhouette tong the: dong vo
     * hay dung thuong tang deu doi duong bao, nen doi "giu nguyen silhouette"
     * choi thang voi chinh cau "the build advances".
     */
    public static function continuation(): string
    {
        return 'The supplied image is the authoritative record of this subject and of the work already '
            .'done to it. The render is taken from the same camera position, with the same framing, '
            .'lighting and setting. Every structure the image already shows keeps the shape, proportion '
            .'and placement it has there. The one change this render makes is the one stated below: it '
            .'may add structure, or alter structure the description names, and the outline of the object '
            .'changes only as far as that change requires. Nothing else in the frame changes. Where a '
            .'part that is not being changed conflicts with the supplied image, the supplied image wins.';
    }

    public static function place(): string
    {
        return 'It shows the permanent place this frame takes place in, in a neutral state. Keep the structure, '
            .'layout and fixed features of the place exactly as the image shows them. The camera, the framing, the '
            .'light and the state of the place in this frame, such as its furniture, props and people, follow the '
            .'description below. The main subject of this film is not in this frame: add no vessel, hull or model '
            .'of one beyond what the description names.';
    }

    public static function hardCut(): string
    {
        return 'The supplied image is the authoritative record of this subject\'s design. Whatever part '
            .'of the subject appears in this frame keeps the shape, proportion, topology and permanent '
            .'openings the image shows for that part. Which parts exist and are visible is set by the '
            .'description below, together with the camera, the setting, the lighting and the composition '
            .'for this frame. Where a visible part conflicts with the supplied image, the supplied image wins.';
    }

    /**
     * Ban v1 giu NGUYEN VAN de mot revision cu doc lai dung prompt no duoc viet
     * cho. Khong sua hai ham nay; can doi thi them mot version moi.
     */
    public static function continuationV1(): string
    {
        return 'The supplied image is the authoritative record of this subject and of the work already '
            .'done to it. The render keeps the same physical object: the same silhouette, proportions, '
            .'topology, structural relationships, permanent openings and overhangs, each exactly as the '
            .'image shows. It keeps the same camera position, framing and lighting, and it keeps every '
            .'structure that is already built, in the place the image shows it. The single change this '
            .'render makes is the one stated below; the build advances by that change and by nothing '
            .'else. Where anything conflicts, the identity in the supplied image wins.';
    }

    public static function hardCutV1(): string
    {
        return 'The supplied image is the authoritative record of this subject\'s identity: the same '
            .'silhouette, proportions, topology and permanent openings, each exactly as the image shows. '
            .'This render places that same subject in the situation stated below, which sets the camera, '
            .'the setting, the lighting and the composition for this frame. Where anything conflicts, '
            .'the identity in the supplied image wins.';
    }
}
