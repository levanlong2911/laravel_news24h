<?php

declare(strict_types=1);

namespace App\Console\Commands\FilmOS;

use App\Services\AI\FilmOS\Benchmark\Selection\ReferenceSelection;
use App\Services\AI\FilmOS\Benchmark\Selection\ScenarioSelectionSource;
use App\Services\AI\FilmOS\Benchmark\Selection\SelectionEvaluator;
use App\Services\AI\FilmOS\Benchmark\Selection\SelectionReport;
use App\Services\AI\FilmOS\Selection\EntityScopedSelectionPolicy;
use App\Services\AI\FilmOS\Selection\Origin;
use App\Services\AI\FilmOS\Selection\SelectionPolicy;
use Illuminate\Console\Command;

/**
 * Phase 1A — policy validation with a human-authored Article Model (ADR-019 §8).
 *
 * Answers one question, for free: if the Article Model is correct, can a policy
 * choose what a person chose? It stops at Shot Truth. No formatter, no prompt, no
 * reducer, no provider, no render, no cost.
 */
final class SelectionInspectCommand extends Command
{
    protected $signature   = 'filmos:selection-inspect {id? : scenario id, or all of them}';
    protected $description = 'Run the selection policy against authored scenarios and score it (free, no render)';

    private const DIRS = [
        'resources/filmos/benchmark/scenarios',
        'resources/filmos/articles',
    ];

    public function handle(): int
    {
        $files = $this->resolve($this->argument('id'));
        if ($files === []) {
            $this->error('No scenario found.');
            return self::FAILURE;
        }

        $policy    = new EntityScopedSelectionPolicy();
        $evaluator = new SelectionEvaluator();
        $reports   = [];
        $skipped   = [];

        foreach ($files as $file) {
            $source = ScenarioSelectionSource::fromFile($file);
            if (!$source->hasIdentity()) {
                $skipped[] = $source->id();
                continue;
            }
            $reports[] = $this->inspect($source, $policy, $evaluator);
        }

        if ($reports === []) {
            $this->error('No scenario carries identity annotations yet.');
            return self::FAILURE;
        }

        $this->summary($reports, $skipped);

        return self::SUCCESS;
    }

    private function inspect(ScenarioSelectionSource $source, SelectionPolicy $policy, SelectionEvaluator $evaluator): SelectionReport
    {
        $model     = $source->articleModel();
        $reference = ReferenceSelection::from($source);

        $truths = [];
        foreach ($source->beatContexts() as $context) {
            $truths[] = $policy->select($model, $context);
        }

        $report = $evaluator->evaluate($model, $truths, $reference);

        $this->newLine();
        $this->line("<options=bold>══ {$model->id}</> <fg=gray>({$model->visualStyle}, topic={$model->topicEntity})</>");
        $this->line(sprintf(
            '   facts %d → visual+relevant (selectable) %d',
            $report->totalFacts,
            $report->selectable,
        ));

        foreach ($report->beats as $beat => $truth) {
            $cmp   = $this->comparisonFor($report, (string) $beat);
            $mark  = $cmp?->matches() ? '<fg=green>✔</>' : '<fg=red>✘</>';
            $ref   = $cmp?->reference ?? '—';
            $arrow = $cmp?->matches() ? '' : " <fg=red>(author: {$ref})</>";

            $this->newLine();
            $this->line(sprintf('   <options=bold>%s</>', strtoupper((string) $beat)));
            $this->line(sprintf('     focus %s %s%s', $mark, $truth->focusEntity, $arrow));

            if ($truth->facts === []) {
                $this->line('     <fg=yellow>no fact is filmable here</>');
                continue;
            }
            foreach ($truth->facts as $fact) {
                $this->line(sprintf(
                    '     <fg=gray>%-3s</> %-18s %s',
                    $fact->factId,
                    '[' . $fact->origin->value . ']',
                    $fact->visualHint,
                ));
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '   focus %d/%d   coverage %d/%d (%.0f%%)',
            $report->focusMatches(),
            count($report->focus),
            $report->used(),
            $report->selectable,
            $report->coverage() * 100,
        ));

        if ($report->starved() !== []) {
            $this->line('   <fg=yellow>never shown: ' . implode(', ', $report->starved()) . '</>');
        }
        if ($report->repeatedEverywhere() !== []) {
            $this->line('   <fg=yellow>said in every beat: ' . implode(', ', $report->repeatedEverywhere()) . '</>');
        }

        return $report;
    }

    private function comparisonFor(SelectionReport $report, string $beat)
    {
        foreach ($report->focus as $cmp) {
            if ($cmp->beat === $beat) {
                return $cmp;
            }
        }
        return null;
    }

    /**
     * @param SelectionReport[] $reports
     * @param string[] $skipped
     */
    private function summary(array $reports, array $skipped = []): void
    {
        $focusHit = $focusAll = $used = $selectable = 0;
        $origins  = [Origin::SHOT_TRUTH->value => 0, Origin::DEFAULT_SEMANTICS->value => 0];

        foreach ($reports as $r) {
            $focusHit   += $r->focusMatches();
            $focusAll   += count($r->focus);
            $used       += $r->used();
            $selectable += $r->selectable;
            foreach ($origins as $k => $_) {
                $origins[$k] += $r->originCounts[$k] ?? 0;
            }
        }

        $total = array_sum($origins) ?: 1;

        $this->newLine();
        $this->line('<options=bold>══ TOTAL</>');
        $this->line(sprintf('   focus agreement with author  %d/%d', $focusHit, $focusAll));
        $this->line(sprintf('   coverage of selectable facts %d/%d', $used, $selectable));
        $this->line(sprintf(
            '   origin  shot_truth %.0f%%   default_semantics %.0f%%',
            $origins[Origin::SHOT_TRUTH->value] / $total * 100,
            $origins[Origin::DEFAULT_SEMANTICS->value] / $total * 100,
        ));
        if ($skipped !== []) {
            $this->newLine();
            $this->line(sprintf(
                '<fg=gray>   skipped %d scenario(s) with no identity annotation: %s</>',
                count($skipped),
                implode(', ', $skipped),
            ));
        }

        $this->newLine();
        $this->line('<fg=gray>   No prompt, no provider, no render. Nothing was spent.</>');
    }

    /** @return string[] */
    private function resolve(?string $id): array
    {
        $found = [];
        foreach (self::DIRS as $dir) {
            foreach (glob(base_path($dir) . '/*.json') ?: [] as $path) {
                if ($id === null || basename($path, '.json') === $id) {
                    $found[] = $path;
                }
            }
        }
        return $found;
    }
}
