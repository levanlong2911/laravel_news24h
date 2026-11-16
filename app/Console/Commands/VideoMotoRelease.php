<?php

namespace App\Console\Commands;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Video\Moto\MotoContract;
use App\Video\Moto\MotoPipelineRelease;
use App\Video\Superyacht\Spec\PhaseResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VideoMotoRelease extends Command
{
    protected $signature = 'video:moto-release
        {project? : Video project id; without it the command lists the moto projects that pinned no release}
        {--to= : With a project: the release it moves to, the direct successor of the one it pinned}
        {--reason= : Why the project moves}
        {--apply : Store the change with the project and keep it in the history of its releases}';

    protected $description = 'Preview (or with --apply store) the release of moto projects. Without a project: every moto project that pinned no release is pinned to what it ran under before the current default — the legacy release when it already ran Phase 2, otherwise the release new projects took before. With a project and --to: the project moves to the next release when no step that release replaces has a selection or a run in progress. No model is called, no run is deleted.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->line($apply ? 'Mode: APPLY' : 'Mode: PREVIEW (read-only, nothing written)');

        return $this->argument('project') === null ? $this->pinInherited($apply) : $this->move((string) $this->argument('project'), $apply);
    }

    private function pinInherited(bool $apply): int
    {
        $pinned = 0;

        foreach (VideoProject::query()->with('article.category')->orderBy('created_at')->get() as $project) {
            if (! $this->moto($project)) {
                continue;
            }

            $release = DB::transaction(function () use ($project, $apply): ?string {
                $locked = VideoProject::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
                $release = MotoPipelineRelease::inherited($locked, $this->ranPhaseTwo((string) $locked->id));

                if ($release !== null && $apply) {
                    MotoPipelineRelease::record($locked, $release, 'Ghim theo bản dự án đã chạy trước khi đổi bản mặc định.', 'video:moto-release');
                }

                return $release;
            });

            $this->line("  {$project->id} · ".mb_substr((string) $project->title, 0, 40).' · '.($release === null ? 'đã ghim '.MotoPipelineRelease::of($project) : ($apply ? 'ghim ' : 'sẽ ghim ').$release));
            $pinned += $release === null ? 0 : 1;
        }

        $this->info($pinned === 0 ? 'Không dự án moto nào còn thiếu ghim.' : ($apply ? "Đã ghim {$pinned} dự án." : "{$pinned} dự án sẽ được ghim. Chạy lại với --apply để ghi."));

        return self::SUCCESS;
    }

    private function move(string $projectId, bool $apply): int
    {
        $project = VideoProject::query()->with('article.category')->find($projectId);
        $to = trim((string) $this->option('to'));
        $reason = trim((string) $this->option('reason'));

        if ($project === null || ! $this->moto($project)) {
            $this->error('Không tìm thấy dự án moto này.');

            return self::FAILURE;
        }

        if ($to === '' || $reason === '') {
            $this->error('Cần --to và --reason.');

            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($projectId, $to, $reason, $apply): array {
            $locked = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $from = MotoPipelineRelease::of($locked);
            $resolver = PhaseResolver::fromConfig((array) config('video.superyacht'));
            $codes = array_map(static fn (int $number): string => "phase-{$number}", range(1, 11));
            $rules = static function (string $release) use ($resolver, $codes): array {
                try {
                    return array_combine($codes, array_map(static fn (string $code): ?array => $resolver->rule(MotoContract::WORKFLOW, $release, $code), $codes));
                } catch (\RuntimeException) {
                    return [];
                }
            };
            $busy = VideoPlanningStage::query()->where('project_id', $projectId)
                ->whereIn('stage', [PlanningStageName::SCENES->value, PlanningStageName::SHOTS->value])
                ->whereIn('status', [VideoPlanningStageStatus::PENDING->value, VideoPlanningStageStatus::RUNNING->value])
                ->pluck('stage')->unique()->values()->all();
            $problems = MotoPipelineRelease::moveProblems($locked, $to, $rules($from), $rules($to), $busy);

            return ['from' => $from, 'problems' => $problems, 'change' => $problems === [] && $apply ? MotoPipelineRelease::record($locked, $to, $reason, 'video:moto-release') : null];
        });

        $this->line("Dự án {$projectId} · ".mb_substr((string) $project->title, 0, 40));
        $this->line("Bản phát hành: {$result['from']} → {$to}");
        $this->line("Lý do: {$reason}");

        foreach ($result['problems'] as $problem) {
            $this->error('Dừng: '.$problem);
        }

        if ($result['problems'] !== []) {
            return self::FAILURE;
        }

        $this->info($result['change'] === null
            ? 'Chuyển được. Chạy lại với --apply để ghi; các lượt cũ giữ nguyên, lượt mới của Scene và Shot dùng prompt, schema và luật của bản mới.'
            : "Đã chuyển lúc {$result['change']['at']}. Các lượt cũ giữ nguyên; không gọi model.");

        return self::SUCCESS;
    }

    private function moto(VideoProject $project): bool
    {
        return MotoContract::is(config('video.superyacht.contracts.categories')[$project->article?->category?->slug ?? ''] ?? null);
    }

    private function ranPhaseTwo(string $projectId): bool
    {
        return VideoPlanningStage::query()->where('project_id', $projectId)->where('stage', PlanningStageName::DESIGN_DNA->value)->exists();
    }
}
