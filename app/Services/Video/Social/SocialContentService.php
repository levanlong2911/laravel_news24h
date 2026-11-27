<?php

declare(strict_types=1);

namespace App\Services\Video\Social;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoSocialEvent;
use App\Models\VideoSocialPackage;
use App\Repositories\Interfaces\VideoPlanningStageRepositoryInterface;
use App\Services\Admin\ClaudeWriterService;
use App\Services\Video\PlanningStageStore;
use App\Services\Video\QueuedStageService;
use App\Services\Video\SpecStageRunner;
use App\Video\Llm\LlmClient;
use App\Video\Script\ScriptEvaluation;
use App\Video\Social\SocialPlan;
use App\Video\Social\SocialReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The two things a model does in Phase 12, each only when a person asks and each paid: it writes the content of one
 * package revision, and it reviews one package revision. Both run through the planning stage store like every other
 * model run, and both name the revision they are for — a run is found by that, never as "the newest run of the project",
 * so the answer for one channel is never read as the answer for another.
 *
 * A model never changes a package. When a writing run ends, its answer becomes a new draft revision only if the revision
 * it was written for is still the newest of its package and is still about the film approved now. Otherwise the answer
 * and its cost stay on their run to be read, the package is left as it is, and no model is called again.
 */
final class SocialContentService implements QueuedStageService
{
    public const WRITE_STEP = 'social_package';

    public const REVIEW_STEP = 'social_review';

    public const NOT_APPLIED = 'ai_not_applied';

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly LlmClient $llm,
        private readonly SocialSourceService $sources,
        private readonly SocialPackageService $packages,
        private readonly VideoPlanningStageRepositoryInterface $stages,
        private readonly ScriptEvaluation $evaluation,
    ) {}

    /** The runner every model run of this phase goes through, as every other phase builds it. */
    private function runner(): SpecStageRunner
    {
        return new SpecStageRunner($this->stageStore, $this->stages, $this->evaluation);
    }

    public static function enabled(): bool
    {
        return SocialSourceService::enabled() && (bool) config('video.social.ai_content');
    }

    /**
     * Asks a model to write the content of a package revision. Paid.
     *
     * @param  ?array{id: string, name: string}  $requester
     * @return array{0: ?VideoPlanningStage, 1: string, 2: ?VideoSocialPackage} the run, queued|cached|replayed|ok or why nothing ran, and the revision the answer became when it applied
     */
    public function write(string $projectId, string $packageId, string $expectedHash, bool $regenerate, string $operationId, ?array $requester = null): array
    {
        [$stage, $reason] = $this->submit(self::WRITE_STEP, PlanningStageName::SOCIAL_PACKAGE, $projectId, $packageId, $expectedHash, $regenerate, $operationId, $requester, 'viết nội dung gói', 'social package');

        if ($stage === null || $stage->status !== VideoPlanningStageStatus::SUCCEEDED->value) {
            return [$stage, $reason, null];
        }

        try {
            [$package, $outcome] = $this->apply($stage, $requester['id'] ?? null);
        } catch (RuntimeException $e) {
            // As on the queue: the answer and its cost stay on the run; an answer the package contract refuses becomes no revision.
            Log::warning('social package: the answer could not be made a revision', ['stage_id' => (string) $stage->id, 'error' => $e->getMessage()]);

            return [$stage, 'Phản hồi của AI đã lưu cùng chi phí nhưng chưa thành revision: '.$e->getMessage(), null];
        }

        return [$stage, $package === null ? $outcome : $reason, $package];
    }

    /**
     * Asks a model to review a package revision. Paid. The review is read beside the revision and changes nothing.
     *
     * @param  ?array{id: string, name: string}  $requester
     * @return array{0: ?VideoPlanningStage, 1: string}
     */
    public function review(string $projectId, string $packageId, string $expectedHash, bool $regenerate, string $operationId, ?array $requester = null): array
    {
        return $this->submit(self::REVIEW_STEP, PlanningStageName::SOCIAL_REVIEW, $projectId, $packageId, $expectedHash, $regenerate, $operationId, $requester, 'review gói', 'social review');
    }

    /** @return array{0: ?VideoPlanningStage, 1: string} what the queued run became when a worker took it */
    public function execute(string $stageId, string $token): array
    {
        $stage = VideoPlanningStage::query()->withoutSnapshot()->find($stageId);

        if ($stage === null) {
            return [null, 'Lượt AI của Social Publishing không còn — bỏ qua.'];
        }

        $review = $stage->stage === PlanningStageName::SOCIAL_REVIEW->value;
        $projectId = (string) $stage->project_id;
        $packageId = (string) ($stage->input_json['package_revision_id'] ?? '');
        $hash = (string) ($stage->input_json['package_hash'] ?? '');
        [$ran, $reason] = $this->runner()->execute(
            $stageId,
            $token,
            fn (): array => $this->prepare($projectId, $packageId, $hash, $review),
            $this->llm,
            fn (array $run, array $evaluation): array => self::output($run, $evaluation),
            $review ? 'review gói' : 'viết nội dung gói',
            $review ? 'social review' : 'social package',
        );

        if (! $review && $ran !== null && $ran->status === VideoPlanningStageStatus::SUCCEEDED->value) {
            try {
                $requester = PlanningStageStore::metadataOf(is_array($ran->input_json) ? $ran->input_json : null)['requested_by']['id'] ?? null;
                [$package, $outcome] = $this->apply($ran, is_string($requester) ? $requester : null);
                $reason = $package === null ? $outcome : $reason;
            } catch (\Throwable $e) {
                // The answer and its cost are stored on the run whatever happens here; a person applies nothing by hand, they ask again.
                Log::error('social package: the answer could not be made a revision', ['stage_id' => $stageId, 'exception' => $e]);
                $reason = 'Phản hồi đã lưu nhưng chưa thành revision: '.$e->getMessage();
            }
        }

        return [$ran, $reason];
    }

    /**
     * Makes the answer of a writing run a new draft revision — once, and only while it still applies. The film is read
     * before the transaction; inside it the package is locked and looked at again, and the selection of the approved
     * film is locked and compared with the film the revision is about.
     *
     * @return array{0: ?VideoSocialPackage, 1: string} the revision and `applied`, or null with why the answer was left on its run
     */
    public function apply(VideoPlanningStage $stage, ?string $actorId): array
    {
        $output = is_array($stage->output_json) ? $stage->output_json : [];
        $pin = (array) ($output['package'] ?? []);
        $projectId = (string) $stage->project_id;

        if ($stage->stage !== PlanningStageName::SOCIAL_PACKAGE->value || $stage->status !== VideoPlanningStageStatus::SUCCEEDED->value || ! is_string($pin['package_revision_id'] ?? null)) {
            return [null, 'Lượt này không phải một lượt viết nội dung đã xong.'];
        }

        if (($output['check']['verdict'] ?? null) !== SocialPlan::PASS || ! is_array($output['document'] ?? null)) {
            $faults = [...(array) ($output['check']['schema'] ?? []), ...(array) ($output['check']['issues'] ?? [])];

            return [null, 'Phản hồi của AI không đạt hợp đồng SocialPublishingPlan nên không thành nội dung gói — phản hồi và chi phí đã lưu: '.implode(' | ', array_slice($faults === [] ? [(string) ($output['check']['error'] ?? $output['json_parse_error'] ?? 'không rõ lỗi')] : $faults, 0, 5))];
        }

        $schemas = $this->sources->policy($projectId)['schemas'];

        try {
            $current = $this->sources->read($projectId)['snapshot']['hash'];
        } catch (RuntimeException) {
            $current = null;
        }

        return DB::transaction(function () use ($stage, $output, $pin, $projectId, $actorId, $schemas, $current): array {
            $rows = VideoSocialPackage::query()->where('package_group_id', $pin['package_group_id'])->where('project_id', $projectId)->lockForUpdate()->orderByDesc('revision')->get();
            $applied = $rows->firstWhere('ai_stage_id', (string) $stage->id);

            if ($applied !== null) {
                return [$rows->where('ai_stage_id', (string) $stage->id)->sortBy('revision')->first(), 'applied'];
            }

            $left = VideoSocialEvent::query()->where('project_id', $projectId)->where('type', self::NOT_APPLIED)->where('payload_json->stage_id', (string) $stage->id)->first();
            $newest = $rows->first();
            $why = $left?->payload_json['reason'] ?? match (true) {
                $newest === null => 'gói không còn',
                (string) $newest->id !== $pin['package_revision_id'] || ! hash_equals((string) $newest->package_hash, (string) $pin['package_hash']) => 'gói đã có revision mới trong lúc AI chạy',
                $current === null || ! hash_equals((string) $newest->source_hash, $current) => 'phim đã duyệt của dự án đã đổi trong lúc AI chạy',
                // The file was hashed before this transaction; that the project still hands on that very film is proved here, under a lock on what selects it.
                $this->sources->selected($projectId, (array) $newest->source_json['pins']) !== null => 'phim đã duyệt của dự án đã đổi trong lúc AI chạy',
                default => null,
            };

            if ($why !== null) {
                if ($left === null) {
                    VideoSocialEvent::record($projectId, self::NOT_APPLIED, (string) $pin['package_revision_id'], null, $actorId, ['stage_id' => (string) $stage->id, 'reason' => $why]);
                }

                return [null, "Kết quả AI không còn áp dụng cho bản hiện hành: {$why}. Phản hồi và chi phí của lượt này được giữ để xem lại; nội dung hiện tại không bị ghi đè và không tự gọi lại AI."];
            }

            return [$this->packages->fromPlan($newest, (array) $output['document'], (string) $stage->id, $actorId, $schemas), 'applied'];
        });
    }

    /**
     * @return list<array<string, mixed>> the writing runs of a package, newest first, each with what it cost and what became of its answer
     */
    public function runs(VideoSocialPackage $package, int $limit = 8): array
    {
        $stages = VideoPlanningStage::query()->withoutSnapshot()
            ->where('project_id', $package->project_id)
            ->where('stage', PlanningStageName::SOCIAL_PACKAGE->value)
            ->where('input_json->package_group_id', (string) $package->package_group_id)
            ->orderByDesc('planning_revision')->limit($limit)->get();
        $ids = $stages->map(static fn (VideoPlanningStage $stage): string => (string) $stage->id)->all();
        $revisions = VideoSocialPackage::query()->where('package_group_id', $package->package_group_id)->whereIn('ai_stage_id', $ids)->orderBy('revision')->get(['id', 'revision', 'ai_stage_id'])->groupBy('ai_stage_id');
        $left = VideoSocialEvent::query()->where('project_id', $package->project_id)->where('type', self::NOT_APPLIED)->get(['payload_json'])->keyBy(static fn (VideoSocialEvent $event): string => (string) ($event->payload_json['stage_id'] ?? ''));

        return $stages->map(static fn (VideoPlanningStage $stage): array => self::describe($stage) + [
            'applied_revision' => $revisions->get((string) $stage->id)?->first()?->revision,
            'not_applied' => $left->get((string) $stage->id)?->payload_json['reason'] ?? null,
        ])->all();
    }

    /**
     * @return ?array<string, mixed> the newest review of exactly this revision, null when nobody asked for one — a revision never reviewed is not a revision that passed
     */
    public function reviewOf(VideoSocialPackage $package): ?array
    {
        $stage = VideoPlanningStage::query()->withoutSnapshot()
            ->where('project_id', $package->project_id)
            ->where('stage', PlanningStageName::SOCIAL_REVIEW->value)
            ->where('input_json->package_revision_id', (string) $package->id)
            ->orderByDesc('planning_revision')->first();

        if ($stage === null) {
            return null;
        }

        $output = is_array($stage->output_json) ? $stage->output_json : [];
        $usable = ($output['check']['verdict'] ?? null) === SocialPlan::PASS && is_array($output['document'] ?? null);

        return self::describe($stage) + [
            'summary' => $usable ? (string) $output['document']['summary'] : null,
            'findings' => $usable ? array_values((array) $output['document']['findings']) : [],
            'not_reviewed' => $usable ? array_values((array) $output['document']['not_reviewed']) : [],
            'basis' => SocialPlan::BASIS,
        ];
    }

    /**
     * @return array{0: ?VideoPlanningStage, 1: string}
     */
    private function submit(string $step, PlanningStageName $name, string $projectId, string $packageId, string $expectedHash, bool $regenerate, string $operationId, ?array $requester, string $label, string $log): array
    {
        if (! self::enabled()) {
            return [null, 'Tạo nội dung bằng AI đang tắt (VIDEO_SOCIAL_AI_CONTENT) — viết và sửa gói bằng tay.'];
        }

        $review = $name === PlanningStageName::SOCIAL_REVIEW;
        [$stage, $reason] = $this->runner()->submit(
            $step,
            $projectId,
            $name,
            fn (): array => $this->prepare($projectId, $packageId, $expectedHash, $review),
            $regenerate,
            $operationId,
            $this->llm,
            fn (array $run, array $evaluation): array => self::output($run, $evaluation),
            $label,
            $log,
            $requester,
        );

        // One run of a step is open for a project at a time. The run handed back may be the one another package is waiting on: it is not this package's run.
        if ($stage !== null && (string) ($stage->input_json['package_revision_id'] ?? '') !== $packageId) {
            return [null, "Đang có một lượt {$label} chạy cho gói khác của dự án này — đợi lượt đó xong rồi bấm lại. Chưa gọi model cho gói này."];
        }

        return [$stage, $reason];
    }

    /**
     * @return array<string, mixed> the run of one revision: what is sent and what pins it
     *
     * @throws RuntimeException when the revision is not the one the page showed or not the newest of its package, the film it is about is no longer the approved one, or the contract, the prompt or the model is not ready; no model is called
     */
    private function prepare(string $projectId, string $packageId, string $expectedHash, bool $review): array
    {
        $package = $this->packages->owned($projectId, $packageId);

        if (! hash_equals((string) $package->package_hash, $expectedHash)) {
            throw new RuntimeException('Gói trên trang không phải bản này — tải lại trang.');
        }

        if ((int) $package->revision !== (int) VideoSocialPackage::query()->where('package_group_id', $package->package_group_id)->max('revision')) {
            throw new RuntimeException('Gói đã có revision mới hơn — tải lại trang rồi chạy trên bản mới.');
        }

        $source = (array) $package->source_json;

        if (! hash_equals((string) $package->source_hash, (string) $this->sources->read($projectId)['snapshot']['hash'])) {
            throw new RuntimeException('Phim đã duyệt của dự án không còn là phim gói này viết cho — chuyển gói sang phim hiện hành trước.');
        }

        $flow = $this->sources->workflowFor($projectId);
        $adapter = $review ? new SocialReview : new SocialPlan;
        $bundle = $this->sources->resolve($flow, (string) $flow['settings'][$review ? 'review_action' : 'package_action']);
        $manifest = $bundle->manifest();
        $phase = $bundle->phase();
        $contract = ScriptEvaluation::contract($manifest);

        if (! str_starts_with($contract, $adapter::CONTRACT.'#')) {
            throw new RuntimeException("Hợp đồng đầu ra là {$contract}; code kiểm cho ".$adapter::CONTRACT.' — phát hành revision tương thích trước.');
        }

        if (! str_contains((string) $phase->prompt_content, 'Input format: `'.$adapter::INPUT.'`') || ! str_contains((string) $phase->prompt_content, 'Output format: `'.$adapter::FORMAT.'`')) {
            throw new RuntimeException("Prompt {$phase->phase_code} revision {$phase->revision} không khai đúng định dạng ".$adapter::INPUT.' → '.$adapter::FORMAT.' mà code đang dựng — phát hành revision tương thích trước.');
        }

        $settings = $adapter->settings();

        if (! ClaudeWriterService::supports($settings['model'])) {
            throw new RuntimeException('Model '.$settings['model'].' không có trong ClaudeWriterService::MODEL_CATALOG.');
        }

        $identity = ['format' => (string) $package->format, 'language' => (string) $package->language];
        $document = (array) $package->document_json;
        $inputs = $review
            ? SocialReview::inputs($source, $identity, $document, $package->content_json['artwork']['recipe']['text']['value'] ?? null)
            : SocialPlan::inputs($source, $identity, (array) $package->content_json['fields']);
        $promptVersion = "{$flow['workflow']}/{$phase->phase_code}@rev{$phase->revision}";
        $payload = (string) json_encode(['project_id' => $projectId, 'prompt_version' => $promptVersion] + $inputs['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $pin = [
            'package_group_id' => (string) $package->package_group_id,
            'package_revision_id' => (string) $package->id,
            'package_hash' => (string) $package->package_hash,
            'source_hash' => (string) $package->source_hash,
        ];

        return [
            'adapter' => $adapter,
            'system' => $adapter->systemText(array_column($bundle->parts(), 'content'), $bundle->outputSchemas()),
            'payload' => $payload,
            'prompt_version' => $promptVersion,
            'settings' => $settings,
            'input' => $pin + [
                'execution' => $settings['execution'],
                'workflow' => $flow['workflow'],
                'bundle_hash' => $bundle->bundleHash(),
                'phase_id' => (string) $phase->id,
                'phase_revision' => (int) $phase->revision,
                'prompt_sha256' => (string) $phase->content_sha256,
                'payload_sha256' => hash('sha256', $payload),
                'model' => $settings['model'],
                'effort' => $settings['effort'],
                'max_tokens' => $settings['max_tokens'],
                'check_version' => $adapter::CHECK_VERSION,
                'inputs_version' => $adapter::INPUT,
            ],
            'metadata' => [
                'manifest' => $manifest,
                'source' => $pin,
                'context' => ['project_id' => $projectId, 'prompt_version' => $promptVersion] + $inputs['context'],
                'payload' => $payload,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>  $evaluation
     * @return array<string, mixed>
     */
    private static function output(array $run, array $evaluation): array
    {
        return [
            'execution' => $run['input']['execution'],
            'format' => $run['adapter']::FORMAT,
            'contract' => $evaluation['contract'],
            'prompt' => array_intersect_key($run['input'], array_flip(['workflow', 'phase_id', 'phase_revision', 'prompt_sha256'])),
            'package' => $run['metadata']['source'],
            'json_parse_error' => $evaluation['parse_error'],
            'document' => $evaluation['document'],
            'derived' => $evaluation['derived'],
            'check' => $evaluation['check'],
            'content_hash' => $evaluation['content_hash'],
        ];
    }

    /** @return array<string, mixed> a run as the screen shows it */
    private static function describe(VideoPlanningStage $stage): array
    {
        $output = is_array($stage->output_json) ? $stage->output_json : [];

        return [
            'id' => (string) $stage->id,
            'status' => (string) $stage->status,
            'running' => $stage->status === VideoPlanningStageStatus::RUNNING->value && $stage->lease_expires_at?->isFuture() === true,
            'pending' => $stage->status === VideoPlanningStageStatus::PENDING->value && $stage->claim_token !== null,
            'error' => $stage->status === VideoPlanningStageStatus::FAILED->value ? $stage->error_message : null,
            'verdict' => $output['check']['verdict'] ?? null,
            'faults' => [...(array) ($output['check']['schema'] ?? []), ...(array) ($output['check']['issues'] ?? [])],
            'warnings' => array_values((array) ($output['check']['warnings'] ?? [])),
            'for_revision_id' => $stage->input_json['package_revision_id'] ?? null,
            'model' => $stage->model,
            'tokens' => ['in' => (int) $stage->tokens_in, 'out' => (int) $stage->tokens_out],
            'cost_usd' => $stage->cost_usd === null ? null : (float) $stage->cost_usd,
            'at' => $stage->updated_at?->toDateTimeString(),
        ];
    }
}
