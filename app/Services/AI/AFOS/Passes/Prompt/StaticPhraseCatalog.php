<?php

namespace App\Services\AI\AFOS\Passes\Prompt;

use App\Services\AI\Support\DeterministicSelector;
use App\Services\AI\AFOS\Types\Emotion;

/**
 * StaticPhraseCatalog — Phase A vocabulary implementation.
 *
 * Two responsibilities:
 *   1. cinematicPhrase()   — maps entity IDs to Kling-ready noun phrases
 *   2. atmosphereVariant() — returns one of N deterministic atmosphere clauses per emotion
 *
 * Phase B replacement: WorldModulePhraseCatalog implements PhraseCatalogInterface
 * and reads from EntityDefinition.cinematicPhrase() in ProductionBible.
 * No changes to KlingPromptPlanningPass required.
 *
 * Design rules:
 *   - No heuristic str_contains() chains — map explicit IDs only
 *   - Fallback is humanize(entityRef), never empty — empty ref resolves to 'subject'
 *   - Variants are deterministic: DeterministicSelector::pick(shotId, variants)
 *   - Each atmosphere variant is a genuinely different visual scenario, not a paraphrase
 */
final class StaticPhraseCatalog implements PhraseCatalogInterface
{
    // ── Entity vocabulary ─────────────────────────────────────────────────────

    /** @var array<string, string> entityId → Kling-ready cinematic noun phrase */
    private const ENTITY_MAP = [
        // ── Villa / Real estate ───────────────────────────────────────────────
        'pool_reflection'  => 'villa pool and its mirror-perfect reflection',
        'pool_surface'     => 'still pool surface',
        'reflection'       => 'glass-still reflection',
        'facade'           => 'stone facade',
        'villa_facade'     => "villa's travertine facade",
        'terrace'          => 'travertine terrace',
        'infinity_edge'    => 'infinity edge meeting the horizon',
        'infinity_pool'    => 'infinity pool',
        'courtyard'        => 'open courtyard',
        'interior'         => 'sun-lit interior',
        'view'             => 'panoramic view beyond',
        'horizon'          => 'horizon line',
        'sky'              => 'open sky',
        'mountain'         => 'mountain range',
        'architecture'     => 'architecture',
        'detail'           => 'architectural detail',
        'material'         => 'material surface',
        'stone'            => 'stone surface',
        'water'            => 'water surface',
        'glass'            => 'glass panel',
        'light'            => 'quality of light',
        'shadow'           => 'shadow pattern',

        // ── People / Characters ───────────────────────────────────────────────
        'subject'          => 'subject',
        'hero_subject'     => 'subject',
        'person'           => 'figure in frame',
        'man'              => 'man',
        'woman'            => 'woman',
        'figure'           => 'human figure',
        'character'        => 'character',
        'chef'             => 'chef at the pass',
        'athlete'          => 'athlete in motion',
        'dancer'           => 'dancer mid-movement',
        'musician'         => 'musician at the instrument',

        // ── Hospitality ───────────────────────────────────────────────────────
        'restaurant'       => 'restaurant interior',
        'dining_room'      => 'dining room',
        'lobby'            => 'hotel lobby',
        'bar'              => 'bar and its backlit bottles',
        'kitchen'          => 'kitchen in full service',
        'dish'             => 'prepared dish',
        'table'            => 'table setting',
        'wine_glass'       => 'wine glass catching the light',

        // ── Nature ────────────────────────────────────────────────────────────
        'forest'           => 'forest interior',
        'trees'            => 'canopy of trees',
        'beach'            => 'shoreline where waves dissolve',
        'ocean'            => 'open ocean',
        'river'            => 'flowing river',
        'waterfall'        => 'falling water',
        'mountain_peak'    => 'mountain peak above the cloud line',
        'desert'           => 'desert expanse',
        'field'            => 'open field',
        'sunset'           => 'setting sun on the horizon',
        'sunrise'          => 'first light breaking the horizon',
        'clouds'           => 'clouds moving across the sky',

        // ── Urban ─────────────────────────────────────────────────────────────
        'city'             => 'city stretching to the horizon',
        'city_skyline'     => 'city skyline at golden hour',
        'street'           => 'street below',
        'alley'            => 'alley receding into shadow',
        'market'           => 'market in full movement',
        'bridge'           => 'bridge spanning the gap',
        'building'         => 'building facade',
        'rooftop'          => 'rooftop above the city',

        // ── Vehicles ─────────────────────────────────────────────────────────
        'vehicle'          => 'vehicle',
        'car'              => 'vehicle in motion',
        'boat'             => 'vessel on the water',
        'yacht'            => 'yacht under sail',

        // ── Abstract / Generic ────────────────────────────────────────────────
        'product'          => 'product',
        'object'           => 'object',
        'surface'          => 'surface',
        'space'            => 'space',
        'form'             => 'form',
    ];

    // ── Atmosphere variants ───────────────────────────────────────────────────

    /**
     * 3 genuinely different visual scenarios per emotion.
     * Selection: abs(crc32(shotId)) % 3 → index 0, 1, or 2.
     * Same shotId always returns same variant.
     *
     * @var array<string, string[]>
     */
    private const ATMOSPHERE_VARIANTS = [
        'serenity' => [
            'Warm golden-hour light moves imperceptibly across still water; the air holds a silence that feels chosen, not empty',
            'Late afternoon sun dissolves harsh edges into soft gradients; the scene breathes at the pace of slow tides, unhurried and certain',
            'Diffused morning light arrives without announcement; surfaces receive it with quiet acceptance — nothing moves, nothing needs to',
        ],
        'luxury' => [
            'Precise light describes every material with care — no surface is accidental, each catches light at a deliberate angle; the space breathes with the weight of considered craft',
            'Light is selective here — it finds the seams, the joins, the grain — and in finding them confirms that everything was chosen, nothing arrived by accident',
            'The space carries the patience of things made to last; light moves across it with the slowness of connoisseurship — certain, deeply considered, beyond hurry',
        ],
        'wonder' => [
            'Light arrives from an unexpected angle, making familiar forms suddenly strange; the environment withholds something just off-frame, drawing the eye further',
            'The frame contains more than it shows; what is visible is a threshold, not a destination — the eye arrives and immediately asks to go deeper',
            'Something has shifted — light falls differently, proportions feel rewritten — the familiar made momentarily unrecognisable, which is the definition of discovery',
        ],
        'power' => [
            'Contrast defines every plane — light compressed against shadow with no mid-tone to soften the force; the environment holds tension like a coiled spring',
            'The frame is a declaration — light stripped of softness, shadow claiming everything it touches; nothing here negotiates or apologises',
            'Mass and shadow dominate; light functions as an instrument of definition, not warmth — cutting edges, reinforcing weight, making scale feel confrontational',
        ],
        'triumph' => [
            'Light floods the space from above and ahead; the environment opens outward as if the world has moved aside to acknowledge the moment',
            'The scene holds the quality of arrival — something earned, finally witnessed; light does not illuminate so much as crown what stands beneath it',
            'Openness is the statement: the frame breathes freely, shadow retreats, the space becomes an affirmation of everything the sequence has been building toward',
        ],
        'curiosity' => [
            'A partial reveal teases what lies beyond; edges hold secrets, the frame is a question more than an answer',
            'The composition withholds deliberately — not every surface is lit, not every form is resolved; the eye circles, searching for the thing the frame refuses to name',
            'Light stops short of full disclosure; there is more here, and the frame is fully aware of it — the question is not what you see, but what you cannot',
        ],
        'tension' => [
            'Shadow and highlight compress the space — air feels charged, as if a threshold is about to be crossed',
            'The frame holds its breath; light defines just enough to confirm proximity to danger — everything else is surrendered to imagination, which is always worse',
            'Compression is the strategy — space narrowed by shadow, highlights exact and surgical; the environment does not comfort, it accumulates pressure',
        ],
        'isolation' => [
            'Vast, deliberate negative space surrounds the subject; silence is not absence but presence — solitude rendered as sovereignty',
            'The world recedes in every direction; the subject is not abandoned but appointed — given the entire frame as a kind of dominion that only stillness can claim',
            'Scale makes the argument: the environment is immeasurably larger than the subject, and yet the subject commands it — not by filling the frame, but by choosing to occupy it',
        ],
    ];

    // ── Interface implementation ──────────────────────────────────────────────

    public function cinematicPhrase(string $entityRef): string
    {
        // Normalize: lowercase, trim, collapse spaces+hyphens to underscores.
        // Allows entity refs from any source: "POOL Reflection", "pool-reflection", "pool_reflection" all resolve.
        $key = (string) preg_replace('/[\s\-]+/', '_', strtolower(trim($entityRef)));

        if ($key === '') {
            return 'subject';
        }

        if (isset(self::ENTITY_MAP[$key])) {
            return self::ENTITY_MAP[$key];
        }

        // Phase A fallback: humanize the entity ID.
        // Phase B: this path should never be reached — all entities defined in WorldModule.
        return str_replace('_', ' ', $key);
    }

    public function atmosphereVariant(Emotion $emotion, string $shotId): string
    {
        $variants = self::ATMOSPHERE_VARIANTS[$emotion->value]
            ?? ['Considered light describes each surface with intention; the space holds the quality of a deliberate choice.'];

        return DeterministicSelector::pick($shotId, $variants);
    }
}
