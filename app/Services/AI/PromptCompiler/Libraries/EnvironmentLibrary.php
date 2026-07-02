<?php

namespace App\Services\AI\PromptCompiler\Libraries;

/**
 * Maps semantic environment keys → rich environment descriptions.
 *
 * Planner outputs semantic keys ("garage", "highway", "studio").
 * Sprint 1 fallback: derive from light code when 'environment' field absent.
 * Phase B (SceneShotPlanner): will output 'environment' field directly.
 */
final class EnvironmentLibrary
{
    public const VERSION = '1.0';

    private const ENVIRONMENTS = [
        // Workshop / workspace
        'garage'       => 'A premium custom motorcycle workshop with polished concrete floors, organized tool walls, industrial metal shelving, warm sunlight through large side windows, subtle floating dust particles, and authentic handcrafted atmosphere.',
        'workshop'     => 'A professional workshop with precision tools neatly arranged, clean hardwood workbenches, focused task lighting, and the quiet energy of skilled craftsmanship.',
        'factory'      => 'A modern industrial manufacturing facility with high ceilings, overhead lighting rigs, immaculate production floor, and the precise energy of large-scale engineering.',

        // Outdoor / natural
        'outdoor'      => 'An open outdoor setting with natural ambient light, organic surroundings, and authentic environmental texture.',
        'mountain'     => 'A dramatic mountain landscape with sweeping vistas, crisp mountain air, rocky terrain, and panoramic scale.',
        'coastal'      => 'A coastal environment with reflected sea light, natural horizon line, sea breeze texture, and the energy of open water.',
        'desert'       => 'A vast desert landscape with dramatic directional light, endless horizon, warm sand tones, and cinematic scale.',
        'forest'       => 'A dense forest setting with dappled natural light filtering through a green canopy, earthy ground texture, and organic ambiance.',

        // Urban / city
        'urban'        => 'A dynamic urban environment with architectural geometry, city-life energy, hard concrete textures, and ambient city light.',
        'street'       => 'An authentic city street with real-world surface texture, organic city atmosphere, and honest documentary feel.',
        'night_city'   => 'A vibrant night cityscape with warm neon reflections on wet pavement, ambient street lighting, glowing signage, and pulsing urban energy.',

        // Studio / controlled
        'studio'       => 'A professional photography studio with precisely controlled lighting, clean neutral backgrounds, and perfect product presentation conditions.',
        'showroom'     => 'A premium automotive showroom with exhibition-quality lighting, polished floor reflections, and curated presentation atmosphere.',
        'gallery'      => 'A refined gallery space with museum-quality directional lighting, clean white walls, and contemplative atmosphere.',

        // Track / road
        'track'        => 'A professional motorsport circuit with smooth tarmac, painted track markings, safety barriers, and the charged atmosphere of competitive racing.',
        'highway'      => 'An open highway with vanishing-point perspective, pristine asphalt texture, white lane markings, and the freedom of open roads.',
    ];

    // Sprint 1 fallback only — light code is an imperfect proxy for environment.
    // SceneShotPlanner (Phase B) will output 'environment' field directly.
    private const LIGHT_FALLBACK = [
        'W1' => 'garage',
        'W2' => 'outdoor',
        'G1' => 'outdoor',
        'N1' => 'night_city',
        'N2' => 'outdoor',
        'D1' => 'outdoor',  // dramatic rim = action/outdoor; studio if needed use 'environment' field
        'S1' => 'outdoor',
        'S2' => 'studio',
        'C1' => 'factory',
        'C2' => 'factory',
    ];

    public static function expand(string $envKey): string
    {
        return self::ENVIRONMENTS[$envKey] ?? ('Environment: ' . str_replace('_', ' ', $envKey) . '.');
    }

    /** Sprint 1 fallback: derive environment from lighting code. */
    public static function fromLightFallback(string $lightCode): string
    {
        $envKey = self::LIGHT_FALLBACK[$lightCode] ?? 'studio';
        return self::expand($envKey);
    }
}
