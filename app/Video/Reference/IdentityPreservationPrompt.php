<?php

namespace App\Video\Reference;

final class IdentityPreservationPrompt
{
    public const VERSION = 'identity-reference-v10';

    public static function editTarget(string $viewLabel, bool $environmentChanges): string
    {
        return 'Edit the supplied image: change only the camera viewpoint to the '.$viewLabel.' view'
            .($environmentChanges ? ' and the surroundings stated below' : '')
            .'. It is the same vessel, not a new design.';
    }

    public static function text(): string
    {
        return 'Keep the vessel\'s identity and geometry: every part the supplied image shows keeps its silhouette, '
            .'proportions, topology, structural relationships, openings, overhangs and state, with the same shape, size '
            .'and place. VIEW GEOMETRY below states the approved design of what this view shows; build every part it '
            .'states exactly as it states it, and where VIEW GEOMETRY is silent continue the visible construction '
            .'plainly, without new features.';
    }

    public static function referenceState(): string
    {
        return 'LIGHTING: Soft, large-source, neutral light with controlled contrast and even exposure that reveals planes, '
            .'edges, setbacks, recesses, overhangs and openings, with a low, diffuse specular response on the metal.'
            ."\n".'SUBJECT STATE: The same bare-metal reference presentation as the supplied image: matte, unpainted metal '
            .'plating with visible weld seams and frame lines. Every pool is a dry, empty basin that keeps its full recess, '
            .'rim and floor. Every glazed opening the geometry states is an open cut-out with its frame, keeping its exact '
            .'size, shape and position. Every open deck, terrace and roof is continuous plating: it has no hatch, skylight, '
            .'well or cut-out that the geometry does not state. No mast, radar, radar arch, satellite dome, horn, navigation light, whip antenna or other '
            .'navigation and communication equipment is installed, and none is drawn: these are finishing equipment fitted '
            .'later. The highest point of the vessel is the roof of the highest permanent structure that VIEW GEOMETRY and '
            .'the supplied image define; nothing rises above it. Preserve all '
            .'design-specified permanent deck structure and stair enclosures. No guardrails, railings, balustrades or glass guards '
            .'are fitted yet: every deck edge, terrace edge and stair side ends as a clean bare-metal edge. '
            .'Do not add equipment or remove fixed geometry. The source below is VIEW GEOMETRY, together with the supplied '
            .'image wherever the image agrees with it. Preserve the source stern outline, its open and closed boundaries, '
            .'platforms and terrace levels; do not widen, narrow, close or reshape the stern to make it more visible. Each '
            .'exterior stair keeps its source start and end landings, deck levels, side, running direction and support; do not '
            .'relocate it inside or outside the hull outline. Each pool, basin and recess stays on its source deck and at its '
            .'source position, with its shape and its relationship to the surrounding deck; do not add, relocate or remove one. '
            .'No water, planting, furniture, loose items or people.'
            ."\n".'Do not add new elements or text. Do not change anything else.';
    }

    public static function derivationStatement(string $sourceSha256): string
    {
        return 'horizontal_flip of artifact '.$sourceSha256.' · '.self::VERSION;
    }
}
