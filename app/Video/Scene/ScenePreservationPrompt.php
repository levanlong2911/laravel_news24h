<?php

namespace App\Video\Scene;

final class ScenePreservationPrompt
{
    public const VERSION = 'scene-preservation-v3';

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
        return [self::LEGACY_VERSION, self::SINGLE_SOURCE_VERSION, self::VERSION];
    }

    public static function forMode(string $mode, ?string $version = null): string
    {
        $version ??= self::SINGLE_SOURCE_VERSION;

        return match (true) {
            $version === self::VERSION && $mode === self::CONTINUATION => self::continuation(),
            $version === self::VERSION && $mode === self::HARD_CUT => self::hardCut(),
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

        if ($version !== self::VERSION || count($roles) < 2) {
            return self::forMode($mode, $version);
        }

        $blocks = ['IMAGE 1 is the editable primary source. '.self::forMode($mode, self::VERSION)];

        foreach (array_slice($roles, 1) as $index => $role) {
            $blocks[] = 'IMAGE '.($index + 2).' '.self::referenceLine($role);
        }

        $blocks[] = 'Only IMAGE 1 is edited. The other images are read, never copied wholesale. '
            .'Never merge geometry from two references that disagree; where any reference disagrees '
            .'with IMAGE 1 about a part that is not being changed, IMAGE 1 wins.';

        return implode("\n\n", $blocks);
    }

    private static function referenceLine(string $role): string
    {
        return match ($role) {
            'identity' => 'is an identity reference only: it shows the same subject from another '
                .'viewpoint so its proportions and permanent features stay right. Do not take its '
                .'camera, framing, lighting or setting.',
            'environment' => 'is an environment reference only: it shows the setting this frame takes '
                .'place in. Take the place, the light and the surrounding materials from it. Do not '
                .'take the subject\'s geometry from it.',
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
