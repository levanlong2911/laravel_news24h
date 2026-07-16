<?php

declare(strict_types=1);

namespace App\Console\Commands\FilmOS;

use App\Services\AI\FilmOS\Benchmark\Scenario\Preview\ConsolePreviewFormatter;
use App\Services\AI\FilmOS\Benchmark\Scenario\Preview\JsonPreviewFormatter;
use App\Services\AI\FilmOS\Benchmark\Scenario\Preview\ScenarioPreview;
use App\Services\AI\FilmOS\Benchmark\Scenario\FactVisuals;
use App\Services\AI\FilmOS\Benchmark\Scenario\ScenarioBootstrapper;
use App\Services\AI\FilmOS\Benchmark\Scenario\ScenarioLoader;
use App\Services\AI\FilmOS\Benchmark\Scenario\ScenarioSchemaException;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditor;
use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;
use App\Services\AI\FilmOS\Planning\BeatOrdinalMap;
use App\Services\AI\FilmOS\Prompting\Adapter\ProviderId;
use App\Services\AI\FilmOS\Prompting\Adapter\PromptRendererRegistry;
use App\Services\AI\FilmOS\Prompting\Compiler\NarrativePromptCompiler;
use Illuminate\Console\Command;

/**
 * "Compiler explorer" for the benchmark: run one scenario through the full
 * pipeline (Loader → Bootstrapper → Auditor → Compiler → Renderer) and show
 * the QA report and rendered prompt BEFORE any video is rendered. Pure — no DB.
 *
 *   php artisan filmos:scenario nfl_last_second_bomb --provider=kling
 *   php artisan filmos:scenario nfl_last_second_bomb --json
 */
final class ScenarioPreviewCommand extends Command
{
    protected $signature = 'filmos:scenario
                            {id : Scenario id (filename without .json)}
                            {--provider=kling : Video provider to render for}
                            {--json : Machine-readable output}';

    protected $description = 'Preview a benchmark scenario: assemble -> QA -> compile -> render, before rendering video';

    public function handle(NarrativeAuditor $auditor, PromptRendererRegistry $registry): int
    {
        $providerId = ProviderId::tryFrom((string) $this->option('provider'));
        if ($providerId === null || !$registry->has($providerId)) {
            $known = implode(', ', array_map(static fn(ProviderId $p) => $p->value, ProviderId::cases()));
            $this->error("Unknown or unregistered provider '{$this->option('provider')}'. Known: {$known}");
            return self::FAILURE;
        }

        try {
            $doc = (new ScenarioLoader())->fromId((string) $this->argument('id'));
        } catch (ScenarioSchemaException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $assembled = (new ScenarioBootstrapper())->assemble($doc);
        $audit     = $auditor->audit($assembled->timeline, $assembled->state);
        $state     = $assembled->state;

        $ir = (new NarrativePromptCompiler())->compile(
            $state->story, $state->characters, $state->scene, $state->world,
            $state->production, $state->performance, $audit,
            FactVisuals::fromFacts($doc->facts),
        );
        $rendered = $registry->get($providerId)->render($ir);

        $beats = BeatOrdinalMap::fromBeats(array_map(
            static fn(string $b) => StoryBeat::from($b),
            array_keys($doc->shots),
        ))->orderedBeats();

        $preview   = new ScenarioPreview($doc, $providerId, $beats, $ir->subjects(), $audit, $rendered);
        $formatter = $this->option('json') ? new JsonPreviewFormatter() : new ConsolePreviewFormatter();

        $this->line($formatter->format($preview));

        return self::SUCCESS;   // preview always succeeds if it renders; QA findings are shown, not fatal
    }
}
