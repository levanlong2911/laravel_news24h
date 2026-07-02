<?php

namespace App\Services\AI\PromptCompiler\Libraries;

/**
 * Asset knowledge base: asset_id → material, texture, color, display name.
 *
 * Planner only knows asset_id. PromptCompiler looks up the rich description.
 * Sprint 1: PHP array. Phase B+: migrate to DB as the library grows to 50k+ assets.
 */
final class AssetLibrary
{
    public const VERSION = '1.0';

    private const ASSETS = [
        // Motorcycle components
        'motorcycle_seat' => [
            'display'   => 'premium leather motorcycle seat',
            'material'  => 'genuine leather',
            'texture'   => 'visible hand stitching',
            'color'     => 'matte black',
            'surface'   => 'soft matte',
            'reflection'=> 'minimal',
        ],
        'motorcycle_engine' => [
            'display'   => 'high-performance motorcycle engine',
            'material'  => 'machined aluminum alloy',
            'texture'   => 'precision milled surfaces',
            'color'     => 'raw silver',
            'surface'   => 'brushed aluminum',
            'reflection'=> 'medium',
        ],
        'motorcycle_tank' => [
            'display'   => 'custom hand-formed fuel tank',
            'material'  => 'hand-formed steel',
            'texture'   => 'smooth painted finish',
            'color'     => 'custom',
            'surface'   => 'high gloss',
            'reflection'=> 'high',
        ],
        'motorcycle_exhaust' => [
            'display'   => 'stainless steel exhaust system',
            'material'  => 'stainless steel',
            'texture'   => 'polished brushed metal',
            'color'     => 'silver chrome',
            'surface'   => 'polished',
            'reflection'=> 'high',
        ],
        'motorcycle_frame' => [
            'display'   => 'custom steel motorcycle frame',
            'material'  => 'chromoly steel',
            'texture'   => 'raw metal welds',
            'color'     => 'matte black',
            'surface'   => 'powder coated',
            'reflection'=> 'low',
        ],
        'motorcycle_wheel' => [
            'display'   => 'wire-spoked motorcycle wheel',
            'material'  => 'chrome-plated steel spokes',
            'texture'   => 'polished rim surface',
            'color'     => 'chrome silver',
            'surface'   => 'polished chrome',
            'reflection'=> 'very high',
        ],

        // Vehicle as subject
        'motorcycle' => [
            'display'   => 'custom built scrambler motorcycle',
            'material'  => 'steel frame with aluminum components',
            'texture'   => 'mixed metal, rubber, and leather surfaces',
            'color'     => 'custom matte finish',
            'surface'   => 'mixed matte and gloss',
            'reflection'=> 'medium',
        ],
    ];

    /** Return full asset record, or null if unknown. */
    public static function describe(string $assetId): ?array
    {
        return self::ASSETS[$assetId] ?? null;
    }

    /** Display name for use in subject sentence. Returns prettified ID if unknown. */
    public static function displayName(string $assetId): string
    {
        return self::ASSETS[$assetId]['display'] ?? str_replace('_', ' ', $assetId);
    }

    /** Full descriptive phrase with material+texture for prompt inclusion. */
    public static function toPromptPhrase(string $assetId): string
    {
        $info = self::ASSETS[$assetId] ?? null;
        if ($info === null) {
            return str_replace('_', ' ', $assetId);
        }
        return "{$info['display']} with {$info['texture']}";
    }
}
