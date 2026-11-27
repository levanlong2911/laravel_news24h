<?php

namespace App\Console\Commands;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\DesignApprovalLock;
use App\Services\Video\DesignDnaService;
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
        {--dna-contracts : Without a project: list the moto projects whose design stands on a DNA and that pinned no Phase 2 contract yet, to pin the contract of that DNA}
        {--apply : Store the change with the project and keep it in the history of its releases}';

    protected $description = 'Preview (or with --apply store) the release of moto projects. Without a project: every moto project that pinned no release is pinned to what it ran under before the current default — the legacy release when it already ran Phase 2, otherwise the release new projects took before. With a project and --to: the project moves to the next release when no step that release replaces has a selection or a run in progress. No model is called, no run is deleted.';

    public function handle(PhaseResolver $resolver): int
    {
        $apply = (bool) $this->option('apply');
        $this->line($apply ? 'Mode: APPLY' : 'Mode: PREVIEW (read-only, nothing written)');

        if ($this->option('dna-contracts')) {
            return $this->pinDnaContracts($apply);
        }

        return $this->argument('project') === null ? $this->pinInherited($apply) : $this->move((string) $this->argument('project'), $apply, $resolver);
    }

    /**
     * @return int success when every moto project either keeps a Phase 2 contract, has no design to keep one for, or can pin the contract of the DNA its design stands on; failure when one cannot be determined. Running it again leaves a pinned contract as it is; no model is called and no run is changed
     */
    private function pinDnaContracts(bool $apply): int
    {
        $dna = app(DesignDnaService::class);
        $lock = app(DesignApprovalLock::class);
        $counts = ['pinned' => 0, 'to_pin' => 0, 'free' => 0, 'unknown' => 0];

        foreach (VideoProject::query()->with('article.category')->orderBy('created_at')->get() as $project) {
            if (! $this->moto($project)) {
                continue;
            }

            $label = "  {$project->id} · ".mb_substr((string) $project->title, 0, 40).' · '.MotoPipelineRelease::of($project).' · ';
            $kept = $project->metadata_json[DesignDnaService::CONTRACT_KEY] ?? null;

            if (is_array($kept['manifest'] ?? null)) {
                [$replayed, $broken] = $dna->replay($kept['manifest']);
                $broken ??= $replayed !== ($kept['bundle_hash'] ?? null) ? 'bundle hash của hợp đồng đã ghim không khớp manifest của nó' : null;

                if ($broken !== null) {
                    $counts['unknown']++;
                    $this->error($label.'hợp đồng ĐÃ GHIM không còn hợp lệ: '.$broken);

                    continue;
                }

                $counts['pinned']++;
                $this->line($label.'đã ghim Phase 2 revision '.($kept['phase_revision'] ?? '?'));

                continue;
            }

            $design = $lock->currentDesign((string) $project->id) ?? VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $project->id)
                ->where('stage', PlanningStageName::YACHT_DESIGN->value)->whereNotNull('input_json->dna_stage_id')->whereNull('input_json->orphan_of_stage_id')->orderByDesc('planning_revision')->first();
            $source = $design?->input_json['dna_stage_id'] ?? null;

            if (! is_string($source) || $source === '') {
                $counts['free']++;
                $this->line($label.'chưa có thiết kế dựng trên DNA — không ghim, theo revision Phase 2 của bản phát hành');

                continue;
            }

            [$contract, $problem] = $dna->contractFrom((string) $project->id, $source);
            $problem ??= $apply ? $dna->pinContract((string) $project->id, $source) : null;

            if ($problem !== null) {
                $counts['unknown']++;
                $this->error($label.'KHÔNG xác định được hợp đồng: '.$problem);

                continue;
            }

            $counts['to_pin']++;
            $this->line($label.($apply ? 'ghim ' : 'sẽ ghim ')."Phase 2 revision {$contract['phase_revision']} (DNA ".substr($source, 0, 8).')');
        }

        $this->info("Đã ghim từ trước: {$counts['pinned']} · ".($apply ? 'vừa ghim' : 'sẽ ghim').": {$counts['to_pin']} · chưa có thiết kế: {$counts['free']} · không xác định được: {$counts['unknown']}.".($apply || $counts['to_pin'] === 0 ? '' : ' Chạy lại với --dna-contracts --apply để ghi.'));

        return $counts['unknown'] === 0 ? self::SUCCESS : self::FAILURE;
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

    private function move(string $projectId, bool $apply, PhaseResolver $resolver): int
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

        $result = DB::transaction(function () use ($projectId, $to, $reason, $apply, $resolver): array {
            $locked = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $from = MotoPipelineRelease::of($locked);

            if ($from === $to) {
                return ['from' => $from, 'problems' => [], 'moved' => [], 'change' => null, 'same' => true, 'dna' => null];
            }
            $codes = array_map(static fn (int $number): string => "phase-{$number}", range(1, 11));
            $rules = static function (string $release) use ($resolver, $codes): array {
                try {
                    return array_combine($codes, array_map(static fn (string $code): ?array => $resolver->rule(MotoContract::WORKFLOW, $release, $code), $codes));
                } catch (\RuntimeException) {
                    return [];
                }
            };
            $busy = VideoPlanningStage::query()->where('project_id', $projectId)
                ->whereIn('stage', MotoPipelineRelease::movableStages())
                ->whereIn('status', [VideoPlanningStageStatus::PENDING->value, VideoPlanningStageStatus::RUNNING->value])
                ->pluck('stage')->unique()->values()->all();
            [$now, $next] = [$rules($from), $rules($to)];
            $ran = VideoPlanningStage::query()->where('project_id', $projectId)->whereNull('input_json->orphan_of_stage_id')->distinct()->pluck('stage')->all();
            $problems = MotoPipelineRelease::moveProblems($locked, $to, $now, $next, $busy, app(DesignApprovalLock::class)->holds($projectId), $ran);
            $moved = array_keys(array_filter($next, static fn (?array $rule, string $code): bool => ($now[$code] ?? null) != $rule, ARRAY_FILTER_USE_BOTH));
            $dna = in_array('phase-2', $moved, true) ? null : VideoPlanningStage::query()->passedDna()->where('project_id', $projectId)->orderByDesc('planning_revision')->value('id');

            return ['from' => $from, 'problems' => $problems, 'moved' => $moved, 'change' => $problems === [] && $apply ? MotoPipelineRelease::record($locked, $to, $reason, 'video:moto-release') : null, 'same' => false, 'dna' => $dna];
        });

        $this->line("Dự án {$projectId} · ".mb_substr((string) $project->title, 0, 40));

        if ($result['same']) {
            $this->info("Dự án đã ở bản {$to} — không ghi gì, lịch sử chuyển giữ nguyên.");

            return self::SUCCESS;
        }

        $this->line("Bản phát hành: {$result['from']} → {$to}");
        $this->line("Lý do: {$reason}");
        $this->line('Phase đổi revision: '.(implode(', ', $result['moved']) ?: 'không phase nào').'.');

        if ($result['dna'] !== null) {
            $this->line("Phase 1 và Phase 2 giữ nguyên revision: DNA đã đạt {$result['dna']} tiếp tục là nguồn của thiết kế, không chạy lại.");
        }

        foreach ($result['problems'] as $problem) {
            $this->error('Dừng: '.$problem);
        }

        if ($result['problems'] !== []) {
            return self::FAILURE;
        }

        $steps = implode(', ', $result['moved']) ?: 'không bước nào';
        $this->info($result['change'] === null
            ? "Chuyển được ({$steps}). Chạy lại với --apply để ghi; các lượt cũ giữ nguyên, lượt mới của các bước này dùng prompt, schema và luật của bản mới."
            : "Đã chuyển lúc {$result['change']['at']} ({$steps}). Các lượt cũ giữ nguyên; không gọi model.");

        if (in_array('phase-3', $result['moved'], true)) {
            $this->warn('Bản thiết kế đang có vẫn là bản của revision cũ: muốn dùng revision Design mới phải chạy lại thiết kế (tính phí); bản vá tay (video:design-revise) chỉ sửa trong hợp đồng mà lượt đã ghim nên không áp cho bản cũ sau khi chuyển.');
        }

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
