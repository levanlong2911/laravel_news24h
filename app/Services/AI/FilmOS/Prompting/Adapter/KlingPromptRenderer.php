<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter;

use App\Services\AI\FilmOS\Prompting\Adapter\Format\Budget;
use App\Services\AI\FilmOS\Prompting\Adapter\Format\BudgetReducer;
use App\Services\AI\FilmOS\Prompting\Adapter\Format\FormattedFragment;
use App\Services\AI\FilmOS\Prompting\Adapter\Format\FormatterRegistry;
use App\Services\AI\FilmOS\Prompting\Adapter\Format\GreedyBudgetReducer;
use App\Services\AI\FilmOS\Prompting\Adapter\Format\Kling\KlingBeatFormatter;
use App\Services\AI\FilmOS\Prompting\Adapter\Format\Kling\KlingConstraintFormatter;
use App\Services\AI\FilmOS\Prompting\Adapter\Format\Kling\KlingProductionFormatter;
use App\Services\AI\FilmOS\Prompting\Adapter\Format\Kling\KlingSceneFormatter;
use App\Services\AI\FilmOS\Prompting\Adapter\Format\Kling\KlingStyleFormatter;
use App\Services\AI\FilmOS\Prompting\Adapter\Format\Kling\KlingSubjectFormatter;
use App\Services\AI\FilmOS\Prompting\Adapter\Format\PromptAssembler;
use App\Services\AI\FilmOS\Prompting\Plan\BeatPlan;
use App\Services\AI\FilmOS\Prompting\Plan\PlanImportance;
use App\Services\AI\FilmOS\Prompting\Plan\PlanItem;
use App\Services\AI\FilmOS\Prompting\Plan\PlanSlot;
use App\Services\AI\FilmOS\Prompting\Plan\RenderPlan;

/**
 * Kling's adapter: walk the plan, format, reduce, assemble.
 *
 *   RenderPlan → FormatterRegistry → FormattedFragment[] → BudgetReducer → Assembler
 *
 * It holds no cinematic logic. Ownership, staging, importance and order were all
 * decided by the RenderPlanner, which does not know this class exists; wording
 * belongs to the formatters; pruning to the reducer; grouping to the assembler.
 * What is left here is the only thing that is genuinely Kling's: which
 * formatters, which budget, which boilerplate, and which section each slot sits
 * in — the vendor's own layout vocabulary.
 *
 * Adding a provider is this class again with different collaborators. Adding
 * knowledge is a new PlanSlot plus a formatter, and nothing here changes.
 */
final class KlingPromptRenderer implements PromptRenderer
{
    /** Which of Kling's sections each slot belongs under; '' is the opener. */
    private const BLOCK = [
        'visual_style'       => '',
        'subject_primary'    => 'SUBJECTS',
        'subject_secondary'  => 'SUBJECTS',
        'subject_background' => 'SUBJECTS',
        'anatomy'            => 'SUBJECTS',
        'environment'        => 'ENVIRONMENT',
        'motif_primary'      => 'VISUAL LANGUAGE',
        'motif_secondary'    => 'VISUAL LANGUAGE',
        'conflict'           => 'KEY VISUALS',
        'key_visual'         => 'KEY VISUALS',
        'hero_moment'        => 'FINAL SHOT',
        'constraint_always'  => 'STYLE',
    ];

    /**
     * Kling's standing instructions. CRITICAL, so they are counted against the
     * budget and never dropped — fixed overhead does not ride free.
     *
     * Kept as short as they can be while still doing their job, because this is
     * a flat tax on EVERY scenario: at 25 words it was spending an eighth of the
     * budget and evicting the hero frame from every piece. What was cut and why:
     *   - "Sharp focus" — every VisualStyle already ends by saying it;
     *   - "no captions" — "text overlays" already covers it;
     *   - "hold each beat's focus subject" — each beat now names its own subject
     *     in the camera line, so this was restating it a fourth time.
     * "text overlays" stays narrowed: a bare "no text" also erases the in-world
     * signage a scene may explicitly want.
     */
    private const BOILERPLATE = [
        'No text overlays, subtitles or watermarks.',
        'One continuous shot, no cuts.',
    ];

    private readonly FormatterRegistry $formatters;
    private readonly Budget $budget;

    public function __construct(
        ?FormatterRegistry $formatters = null,
        ?Budget $budget = null,
        private readonly BudgetReducer $reducer = new GreedyBudgetReducer(),
        private readonly PromptAssembler $assembler = new PromptAssembler(),
    ) {
        $this->budget     = $budget ?? Budget::kling();
        $this->formatters = $formatters ?? new FormatterRegistry([
            new KlingStyleFormatter(),
            new KlingSubjectFormatter(),
            new KlingSceneFormatter(),
            new KlingBeatFormatter(),
            new KlingProductionFormatter(),
            new KlingConstraintFormatter(),
        ]);
    }

    public function provider(): ProviderId
    {
        return ProviderId::KLING;
    }

    public function render(RenderPlan $plan): RenderedPrompt
    {
        $fragments = $this->reducer->reduce($this->format($plan), $this->budget);

        return new RenderedPrompt(
            positive: $this->assembler->assemble($fragments),
            negative: $this->negative($plan),
            metadata: $this->metadata($plan),
        );
    }

    /** @return FormattedFragment[] */
    private function format(RenderPlan $plan): array
    {
        $fragments = [];

        foreach ($plan->global as $item) {
            $fragments[] = $this->fragment($item, self::BLOCK[$item->slot->value] ?? '');
        }
        foreach ($plan->beats as $beat) {
            foreach ($beat->items as $item) {
                $fragments[] = $this->fragment($item, $this->beatLabel($beat));
            }
        }
        foreach ($plan->ending as $item) {
            $fragments[] = $this->fragment($item, self::BLOCK[$item->slot->value] ?? '');
        }
        foreach ($plan->constraints as $item) {
            $fragments[] = $this->fragment($item, self::BLOCK[$item->slot->value] ?? '');
        }
        foreach (self::BOILERPLATE as $i => $text) {
            $fragments[] = new FormattedFragment('STYLE', PlanImportance::CRITICAL, $i, $text);
        }

        return array_values(array_filter($fragments, static fn(?FormattedFragment $f): bool => $f !== null));
    }

    private function fragment(PlanItem $item, string $block): ?FormattedFragment
    {
        $formatter = $this->formatters->for($item->slot);
        if ($formatter === null) {
            return null;   // this vendor cannot say it; silence is a valid prompt
        }
        $text = $formatter->format($item->slot, $item->payload);

        return $text === '' ? null : new FormattedFragment($block, $item->importance, $item->order, $text);
    }

    private function beatLabel(BeatPlan $beat): string
    {
        return $beat->beat !== null ? strtoupper($beat->beat->value) : 'SHOT ' . ($beat->ordinal + 1);
    }

    /** NEVER constraints live in Kling's separate negative field, free of the budget. */
    private function negative(RenderPlan $plan): string
    {
        // "text overlay" not bare "text": bare "text" also suppresses in-world
        // signage (a scoreboard, a jersey number) the scene explicitly asks for.
        $terms = ['extra limbs', 'deformed hands', 'warping', 'text overlay', 'subtitles', 'watermark'];

        foreach ($plan->constraints as $item) {
            if ($item->slot === PlanSlot::CONSTRAINT_NEVER) {
                $terms[] = trim("{$item->payload->target} {$item->payload->rule}");
            }
        }

        return implode(', ', $terms);
    }

    /** @return array<string, mixed> */
    private function metadata(RenderPlan $plan): array
    {
        $energyPeak = null;
        foreach ($plan->beats as $beat) {
            foreach ($beat->items as $item) {
                if ($item->slot === PlanSlot::MOTION) {
                    $energyPeak = max($energyPeak ?? 0, (int) $item->payload);
                }
            }
        }

        $meta = ['provider' => ProviderId::KLING->value];
        if ($energyPeak !== null) {
            $meta['energy_peak'] = $energyPeak;
        }
        return $meta;
    }
}
