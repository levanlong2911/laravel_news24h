<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter\Format\Kling;

use App\Services\AI\FilmOS\Narrative\Production\VisualConstraint;
use App\Services\AI\FilmOS\Prompting\Adapter\Format\SlotFormatter;
use App\Services\AI\FilmOS\Prompting\Plan\PlanSlot;

/**
 * The director's hard rules.
 *
 * ALWAYS becomes positive reinforcement. NEVER says nothing here: it belongs in
 * Kling's negative prompt, a separate field the renderer fills, so it returns ''
 * rather than being special-cased upstream.
 *
 * AUTHORING CONVENTION: $target is a BARE noun — "football", "cars", "line of
 * gold light" — because this supplies the article. Authoring "both cars" yields
 * "keep the both cars", and no amount of formatter cleverness fixes a target that
 * already brought its own determiner; guessing at leading quantifiers would be
 * exactly the brittle string-sniffing this codebase refuses elsewhere.
 */
final class KlingConstraintFormatter implements SlotFormatter
{
    use JoinsPhrases;

    public function slots(): array
    {
        return [PlanSlot::CONSTRAINT_ALWAYS, PlanSlot::CONSTRAINT_NEVER];
    }

    public function format(PlanSlot $slot, mixed $payload): string
    {
        assert($payload instanceof VisualConstraint);

        return match ($slot) {
            PlanSlot::CONSTRAINT_ALWAYS => $this->sentence(
                "keep the {$payload->target} {$payload->rule} in every frame; never lose the {$payload->target}",
            ),
            // Negative prompt territory — see KlingPromptRenderer::negative().
            default => '',
        };
    }
}
