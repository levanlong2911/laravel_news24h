<?php

namespace App\Services\AI\ScenePlanner;

/**
 * CompositionEvolutionPlanner — per-beat depth field evolution.
 *
 * The static CompositionPlanner sets foreground/midground/background once for
 * the entire shot. Real cinema never works this way: depth layers shift across
 * the arc as the camera moves through space.
 *
 * Pattern across the 4-beat arc:
 *   hook       — extreme close, no depth layers (identity withheld, world withheld)
 *   escalation — foreground appears, midground emerging, background hinting
 *   reveal     — all 3 depth layers fully populated, world declared
 *   payoff     — foreground removed, subject small in vast background — scale overwhelm
 *
 * This creates the cinematic sensation of "entering a world" rather than just
 * watching a camera fly.
 *
 * Injected into the 'subject' field per beat (depth layers describe what IS
 * in the frame at each depth plane, extending the subject description).
 */
final class CompositionEvolutionPlanner
{
    private const BEAT_COMPOSITIONS = [
        'aerial_vehicle' => [
            'hook' => [
                'foreground' => 'cloud layer fills frame — thick white diffusion, no depth',
                'midground'  => 'none — atmosphere withholds all structure below',
                'background' => 'none — depth layers entirely concealed by cloud cover',
            ],
            'escalation' => [
                'foreground' => 'cloud wisps clearing at frame edge — ocean surface registering below',
                'midground'  => 'vessel silhouette emerging in mid-depth — hull form barely legible through haze',
                'background' => 'horizon line appearing — sky and water boundary opening',
            ],
            'reveal' => [
                'foreground' => 'bow spray and wake foam in close foreground — water texture sharp and immediate',
                'midground'  => 'hull and deck at natural depth — surface definition clear',
                'background' => 'ocean extending to horizon — full environmental scale context',
            ],
            'payoff' => [
                'foreground' => 'none — foreground removed — yacht surrounded by pure ocean on all sides',
                'midground'  => 'yacht small and sharp at centre-mid — vessel as point of beauty',
                'background' => 'infinite ocean surface and sky filling frame — full scale declared',
            ],
            'resolution' => [
                'foreground' => 'none',
                'midground'  => 'yacht receding — becoming a distant point against ocean',
                'background' => 'ocean fills entire frame — vessel absorbed into environment',
            ],
        ],
        'athletic_action' => [
            'hook' => [
                'foreground' => 'face and eyes in extreme close detail — pores, focus, locked determination',
                'midground'  => 'none — tight frame excludes all body context',
                'background' => 'stadium floodlights as pure bokeh wash — warm amber out-of-focus energy',
            ],
            'escalation' => [
                'foreground' => 'jersey fabric and arm loaded in foreground — tension visible in material grain',
                'midground'  => 'body and plant foot at mid-depth — full stance and power loading readable',
                'background' => 'crowd rising — human motion blur behind the action',
            ],
            'reveal' => [
                'foreground' => 'hands and ball at release contact in sharp foreground isolation',
                'midground'  => 'full body at extension — throwing motion at peak angle',
                'background' => 'field depth opens — yard lines and end zone visible at distance',
            ],
            'payoff' => [
                'foreground' => 'none — subject recedes into full environmental context',
                'midground'  => 'quarterback small in wide frame — human scale against stadium scale',
                'background' => 'full stadium visible — crowd, flags, lights, scale overwhelming',
            ],
        ],
        'landscape_nature' => [
            'hook' => [
                'foreground' => 'geological surface texture fills frame — rock, earth, or ice — no scale reference',
                'midground'  => 'none — macro frame excludes depth entirely',
                'background' => 'none — identity and scale both withheld',
            ],
            'escalation' => [
                'foreground' => 'cliff edge or canyon rim appearing — scale entering frame from below',
                'midground'  => 'valley or ravine visible at natural depth — geological mass registering',
                'background' => 'distant peaks or horizon emerging — depth structure declaring itself',
            ],
            'reveal' => [
                'foreground' => 'tree line or waterfall in close foreground — organic life layer',
                'midground'  => 'primary geological feature at full visible depth',
                'background' => 'full landscape opening — mountains, sky, scale complete',
            ],
            'payoff' => [
                'foreground' => 'none — foreground removed — landscape breathes in wide open space',
                'midground'  => 'landscape feature small at natural depth — human scale reference absent',
                'background' => 'entire terrain visible to horizon — geological time and scale declared',
            ],
        ],
        'product_craft' => [
            'hook' => [
                'foreground' => 'material surface texture extreme close — grain, weave, or metal at pore level',
                'midground'  => 'none — macro frame excludes object identity',
                'background' => 'none — pure material abstraction, function withheld',
            ],
            'escalation' => [
                'foreground' => 'object edge and silhouette emerging — form becoming legible from texture',
                'midground'  => 'object body at natural depth — mass and proportion readable',
                'background' => 'studio surface or context environment at soft depth behind the object',
            ],
            'reveal' => [
                'foreground' => 'key defining detail in close foreground — mechanism, logo, or signature feature',
                'midground'  => 'complete object at natural display depth — function declared',
                'background' => 'supporting context — surface reflection, brand environment',
            ],
            'payoff' => [
                'foreground' => 'none — clean frame — object alone in space',
                'midground'  => 'full product centred — complete, final, definitive',
                'background' => 'minimal clean environment — pure presentation space',
            ],
        ],
        'generic' => [
            'hook' => [
                'foreground' => 'subject presence in extreme close — scale registered, identity withheld',
                'midground'  => 'none — tight frame withholds all context',
                'background' => 'abstract environmental energy — bokeh or texture wash',
            ],
            'escalation' => [
                'foreground' => 'subject detail layer emerging — form becoming readable',
                'midground'  => 'subject body at natural depth — context entering frame',
                'background' => 'environment at soft depth — scale beginning to register',
            ],
            'reveal' => [
                'foreground' => 'defining detail in close foreground — key element of subject identity',
                'midground'  => 'full subject at natural depth — complete form declared',
                'background' => 'full environmental context — depth structure complete',
            ],
            'payoff' => [
                'foreground' => 'none — space opens around subject',
                'midground'  => 'subject in natural environment at full depth',
                'background' => 'complete environmental scale — subject placed in world',
            ],
        ],
    ];

    /**
     * @param  string $category From CinematicBeatPlan::$category
     * @param  array  $beats    CinematicBeatPlan::$beats — [{beat, ...}]
     * @return array            {beats: [{beat, foreground, midground, background}]}
     */
    public function plan(string $category, array $beats): array
    {
        $profile = self::BEAT_COMPOSITIONS[$category] ?? self::BEAT_COMPOSITIONS['generic'];
        $result  = [];

        foreach ($beats as $beat) {
            $beatName = $beat['beat'] ?? '';
            if ($beatName === '') {
                continue;
            }
            $comp     = $profile[$beatName] ?? $profile['payoff'];
            $result[] = array_merge(['beat' => $beatName], $comp);
        }

        return ['beats' => $result];
    }
}
