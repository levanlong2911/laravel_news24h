<?php

namespace App\Console\Commands;

use App\Services\Video\PlanningStageStore;
use App\Services\Video\ShotSourceService;
use App\Video\Moto\MotoShotsCheck;
use Illuminate\Console\Command;

class VideoMotoShotCoverage extends Command
{
    protected $signature = 'video:moto-shot-coverage
        {project : Video project id}
        {--stage= : Shot list stage id; the approved shot plan of the project when omitted}
        {--json : Print the report as JSON}';

    protected $description = 'Report, scene by scene, how a stored moto shot plan covers the events and the work of its pinned scene plan; read-only, no model is called and nothing is written';

    public function __construct(private readonly ShotSourceService $shots)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $projectId = (string) $this->argument('project');
        $stageId = $this->option('stage');
        $stageId = is_string($stageId) && $stageId !== '' ? $stageId : ($this->shots->selection($projectId)['stage_id'] ?? null);

        if (! is_string($stageId)) {
            $this->error('The project has no approved shot plan — pass --stage=<shot stage id>.');

            return self::FAILURE;
        }

        $stage = $this->shots->shotStage($projectId, $stageId);

        if ($stage === null) {
            $this->error("Shot stage {$stageId} is not a finished shot plan of project {$projectId}.");

            return self::FAILURE;
        }

        [$evaluation, $problem] = $this->shots->inspect($projectId, $stage);

        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $context = (array) (PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)['context'] ?? []);
        $policy = $context['shot_editing_policy'] ?? null;

        if ($policy !== MotoShotsCheck::COVERAGE) {
            $this->error('Shot stage '.$stageId.' is written under policy '.json_encode($policy).'; this report reads plans of '.MotoShotsCheck::COVERAGE.'.');

            return self::FAILURE;
        }

        $verdict = (string) ($evaluation['check']['verdict'] ?? MotoShotsCheck::UNSCORED);
        $report = MotoShotsCheck::coverageReport((array) ($evaluation['derived']['shots'] ?? $evaluation['document']['data']['shots'] ?? []), $context);
        $budget = (int) ($context['shot_budget']['max'] ?? 0);
        $problems = [
            ...($verdict === MotoShotsCheck::PASS ? [] : ["Check {$verdict}: ".implode(' | ', array_map(static fn (mixed $issue): string => is_string($issue) ? $issue : (string) json_encode($issue, JSON_UNESCAPED_UNICODE), [...(array) ($evaluation['check']['schema'] ?? []), ...(array) ($evaluation['check']['issues'] ?? [])]))]),
            ...($budget > 0 && $report['shot_count'] > $budget ? ["{$report['shot_count']} shot vượt ngân sách {$budget} shot của phim."] : []),
            ...$report['problems'],
        ];
        $result = [
            'project' => $projectId,
            'stage' => $stageId,
            'policy' => $policy,
            'verdict' => $verdict,
            'budget' => $budget,
            'shot_count' => $report['shot_count'],
            'duration_ms' => $report['duration_ms'],
            'scenes' => $report['scenes'],
            'problems' => $problems,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $problems === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->line("Shot plan {$stageId} · {$policy} · check {$verdict} · {$report['shot_count']}/{$budget} shot · {$report['duration_ms']} ms");

        foreach ($report['scenes'] as $scene) {
            $this->newLine();
            $this->info("{$scene['scene']} · {$scene['shot_count']} shot · {$scene['duration_ms']} ms · ".implode(', ', $scene['shots']));

            foreach ($scene['events'] as $event) {
                $this->line("  {$event['event']}: ".($event['progress'] === [] ? '—' : implode(' → ', array_map(static fn (array $link): string => "{$link['shot']} {$link['phase']} [".implode(',', $link['step_ids']).']', $event['progress']))));
            }

            foreach ($scene['work'] as $item) {
                $this->line("  work {$item['id']} ({$item['operation']} {$item['part']}): events ".(implode(', ', $item['events']) ?: '—').' · completed in '.($item['completed_in'] ?? '—'));
            }

            foreach (['missing' => 'missing', 'extra' => 'not of the scene', 'out_of_order' => 'played in order', 'completed_twice' => 'completed more than once'] as $key => $label) {
                if ($scene[$key] !== []) {
                    $this->line("  {$label}: ".implode(', ', $scene[$key]));
                }
            }
        }

        $this->newLine();

        if ($problems === []) {
            $this->info('Every event and every work item of the scene plan is covered.');

            return self::SUCCESS;
        }

        foreach ($problems as $line) {
            $this->error($line);
        }

        return self::FAILURE;
    }
}
