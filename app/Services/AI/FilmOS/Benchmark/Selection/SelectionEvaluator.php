<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Selection;

use App\Services\AI\FilmOS\Selection\ArticleModel;
use App\Services\AI\FilmOS\Selection\BeatContext;
use App\Services\AI\FilmOS\Selection\Origin;
use App\Services\AI\FilmOS\Selection\ShotTruth;

/**
 * Scores a prediction that was made without it.
 *
 * Runs strictly after the policy. The dependency points one way — Selection never
 * imports this namespace — so a policy cannot reach the reference even by accident.
 *
 * It measures four things, and deliberately does not measure staging: BeatContext
 * supplies staging as an input, so there is no staging prediction to score.
 */
final class SelectionEvaluator
{
    public function __construct(
        private readonly EligibilityAttributor $attributor = new EligibilityAttributor(),
    ) {}

    /**
     * @param BeatContext[] $contexts
     * @param ShotTruth[] $truths
     */
    public function evaluate(
        ArticleModel $model,
        array $contexts,
        array $truths,
        ReferenceSelection $reference,
    ): SelectionReport {
        $selectable = [];
        foreach ($model->selectableFacts() as $f) {
            $selectable[$f->id] = 0;
        }

        $focus   = [];
        $origins = [Origin::SHOT_TRUTH->value => 0, Origin::DEFAULT_SEMANTICS->value => 0];
        $beats   = [];

        foreach ($truths as $truth) {
            foreach ($truth->facts as $fact) {
                if (array_key_exists($fact->factId, $selectable)) {
                    $selectable[$fact->factId]++;
                }
                $origins[$fact->origin->value]++;
            }

            $expected = $reference->focusFor($truth->beat);
            $focus[]  = new FocusComparison(
                beat:      $truth->beat,
                predicted: $truth->focusEntity,
                reference: $expected,
            );

            $beats[$truth->beat] = $truth;
        }

        return new SelectionReport(
            modelId:      $model->id,
            totalFacts:   count($model->facts),
            selectable:   count($selectable),
            usageByFact:  $selectable,
            focus:        $focus,
            originCounts: $origins,
            beats:        $beats,
            attributions: $this->attributor->attribute($model, $contexts, $truths),
        );
    }
}
