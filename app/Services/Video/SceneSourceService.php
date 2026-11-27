<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoReviewDecision;
use App\Repositories\Interfaces\VideoPlanningStageRepositoryInterface;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Repositories\Interfaces\VideoReviewDecisionRepositoryInterface;
use App\Video\Moto\MotoScenes;
use App\Video\Scenes\SceneWorkflow;
use App\Video\Script\ScriptEvaluation;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Video\Moto\MotoCheckRules;
use RuntimeException;

final class SceneSourceService
{
    public const ENTITY_TYPE = 'scenes';

    public const SELECTION_KEY = 'scenes_selection';

    public const TOKEN_VERSION = 'scenes-source-token-v1';

    public const SHOT_PLAN_MISSING = 'Chưa có kế hoạch shot hợp lệ — shot, reference, keyframe và clip bị khoá cho tới khi có kế hoạch shot đã duyệt.';

    public const RENDER_NOT_READY = 'Kế hoạch shot đã duyệt — keyframe làm ở màn Keyframes; render clip và phim cuối còn khoá.';

    public function __construct(
        private readonly ScriptEvaluation $evaluation,
        private readonly VideoProjectRepositoryInterface $projects,
        private readonly VideoPlanningStageRepositoryInterface $stages,
        private readonly VideoReviewDecisionRepositoryInterface $decisions,
    ) {}

    /** @return array{workflow: string, action: string, adapter: SceneWorkflow, category_id: string, category_slug: string} */
    public function workflowFor(string $projectId): array
    {
        $project = $this->projects->findProjectWithCategory($projectId) ?? throw new RuntimeException('Không tìm thấy dự án.');
        $category = $project->article?->category ?? throw new RuntimeException('Dự án không gắn bài viết có chủ đề — Phase 6 không áp dụng.');
        $workflow = (string) (config('video.superyacht.contracts.categories')[$category->slug] ?? '');
        $entry = $workflow === '' ? null : (config('video.scenes.workflows')[$workflow] ?? null);

        if (! is_array($entry)) {
            throw new RuntimeException("Chủ đề {$category->slug} chưa hỗ trợ Phase 6 (workflow ".($workflow === '' ? 'chưa cấu hình' : $workflow).').');
        }

        $adapter = app((string) $entry['adapter']);

        if (! $adapter instanceof SceneWorkflow) {
            throw new RuntimeException("Adapter Phase 6 của workflow {$workflow} không hợp lệ.");
        }

        if ($adapter instanceof \App\Video\Moto\ReleaseBound) {
            $adapter->bind($project);
        }

        return ['workflow' => $workflow, 'action' => (string) $entry['action'], 'adapter' => $adapter, 'category_id' => (string) $category->id, 'category_slug' => (string) $category->slug];
    }

    public function supports(string $projectId): bool
    {
        try {
            $this->workflowFor($projectId);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @param  ?string  $scope  'clips' asks for the clip step, which the approved shot plan opens
     * @return ?string why no shot, reference, keyframe or clip action may run for a project whose workflow plans scenes, null for any other project
     */
    public function shotPlanProblem(string $projectId, ?string $scope = null): ?string
    {
        if (! $this->supports($projectId)) {
            return null;
        }

        if (! is_array($this->projects->findProject($projectId)?->metadata_json[ShotSourceService::SELECTION_KEY] ?? null)) {
            return self::SHOT_PLAN_MISSING;
        }

        return $scope === 'clips' ? null : self::RENDER_NOT_READY;
    }

    public function sceneStage(string $projectId, string $stageId): ?VideoPlanningStage
    {
        return $this->stages->findSucceededSourceStage($projectId, $stageId, PlanningStageName::SCENES);
    }

    /** @return ?array{stage_id: string, content_hash: string, approval_id: string, version: int, approved_at: string} */
    public function selection(string $projectId): ?array
    {
        $selection = $this->projects->findProject($projectId)?->metadata_json[self::SELECTION_KEY] ?? null;

        return is_array($selection) ? $selection : null;
    }

    /** @return array{0: array<string, mixed>, 1: ?string} a scene stage re-evaluated from its raw answer and pinned contract, or why it cannot be trusted */
    public function inspect(string $projectId, VideoPlanningStage $stage): array
    {
        try {
            $flow = $this->workflowFor($projectId);
        } catch (RuntimeException $e) {
            return [[], $e->getMessage()];
        }

        return $this->evaluate($stage, $flow['adapter']);
    }

    /** @return array<string, mixed> */
    public function source(VideoPlanningStage $stage): array
    {
        return (array) (PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)['source'] ?? []);
    }

    /**
     * RULE PASS is the approval of a scene plan: nobody is asked. The decision keeps reviewer_id empty and basis rule_pass,
     * so it never reads as a person's review; a run that fails the check, or whose source moved, stays unapproved.
     *
     * @return array{0: ?VideoReviewDecision, 1: string}
     */
    public function approvePassed(string $projectId, string $stageId): array
    {
        $stage = $this->sceneStage($projectId, $stageId);

        if ($stage === null) {
            return [null, 'Bản chia scene này không thuộc dự án hoặc chưa chạy thành công.'];
        }

        [$evaluation, $problem] = $this->inspect($projectId, $stage);

        if ($problem !== null) {
            return [null, $problem];
        }

        if (($stale = $this->staleSource($projectId, $stage)) !== null) {
            return [null, $stale];
        }

        $selection = $this->selection($projectId);

        if (($selection['stage_id'] ?? null) === $stageId && ($selection['content_hash'] ?? null) === $evaluation['content_hash']) {
            return [null, 'already_approved'];
        }

        return $this->approve($projectId, $stageId, (string) $evaluation['content_hash'], (int) ($selection['version'] ?? 0), (string) Str::uuid(), null);
    }

    /** @return ?string why the sources a scene run pinned — the approved script, the approved backgrounds and the design they pin — are no longer the ones in use, null while they are; a newer prompt revision is no change of source */
    public function staleSource(string $projectId, VideoPlanningStage $stage): ?string
    {
        try {
            $problem = $this->workflowFor($projectId)['adapter']->sourceProblem($projectId, $this->source($stage));
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return $problem === null ? null : 'Nguồn của bản chia scene cần cập nhật: '.$problem;
    }

    /** @return array{0: ?VideoReviewDecision, 1: string} */
    public function approve(string $projectId, string $stageId, string $expectedHash, int $expectedVersion, string $operationId, ?string $reviewerId): array
    {
        if (! Str::isUuid($operationId)) {
            return [null, 'Mã thao tác duyệt không hợp lệ — tải lại trang.'];
        }

        try {
            $flow = $this->workflowFor($projectId);
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }

        return DB::transaction(function () use ($projectId, $stageId, $expectedHash, $expectedVersion, $operationId, $reviewerId, $flow): array {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->first();

            if ($project === null) {
                return [null, 'Không tìm thấy dự án.'];
            }

            $payloadHash = hash('sha256', json_encode([$projectId, $stageId, 'approved', $expectedHash, $expectedVersion], JSON_THROW_ON_ERROR));
            $replayed = VideoReviewDecision::query()->where('project_id', $projectId)->where('entity_type', self::ENTITY_TYPE)
                ->where('metadata_json->operation_id', $operationId)->first();

            if ($replayed !== null) {
                return hash_equals((string) ($replayed->metadata_json['operation_payload_hash'] ?? ''), $payloadHash)
                    ? [$replayed, 'replayed']
                    : [null, 'Mã thao tác này đã dùng cho một lần duyệt khác — tải lại trang.'];
            }

            $stage = $this->sceneStage($projectId, $stageId);

            if ($stage === null) {
                return [null, 'Bản chia scene này không thuộc dự án hoặc chưa chạy thành công.'];
            }

            [$evaluation, $problem] = $this->evaluate($stage, $flow['adapter']);

            if ($problem !== null) {
                return [null, $problem];
            }

            if (! hash_equals((string) $evaluation['content_hash'], $expectedHash)) {
                return [null, 'Trang đang hiển thị một nội dung khác bản đã lưu — tải lại trang.'];
            }

            $selection = (array) ($project->metadata_json[self::SELECTION_KEY] ?? []);
            $version = (int) ($selection['version'] ?? 0);

            if ($version !== $expectedVersion) {
                return [null, 'Lựa chọn scene đã đổi ở một tab khác — tải lại trang rồi duyệt lại.'];
            }

            $check = $evaluation['check'];

            if (! MotoCheckRules::usable($check)) {
                return [null, $check['verdict'] !== 'PASS' ? 'Bản chia scene này chưa đạt kiểm RULE — không duyệt được.' : 'Bản chia scene này còn điều kiện chưa đạt để dùng cho bước sau: '.implode('; ', (array) $check['requirements']).' — không duyệt được.'];
            }

            if ($check['status'] !== 'ok') {
                return [null, 'Model trả status '.json_encode($check['status']).' — còn xung đột chặn; bản này chỉ để xem.'];
            }

            if (($problem = $flow['adapter']->sourceProblem($projectId, $this->source($stage))) !== null) {
                return [null, 'Nguồn của bản chia scene cần cập nhật: '.$problem];
            }

            $decision = VideoReviewDecision::create([
                'project_id' => $projectId,
                'session_id' => null,
                'entity_type' => self::ENTITY_TYPE,
                'entity_id' => $stageId,
                'decision' => 'approved',
                'revision' => 1 + (int) VideoReviewDecision::query()->where('entity_type', self::ENTITY_TYPE)->where('entity_id', $stageId)->max('revision'),
                'reviewer_id' => $reviewerId,
                'reason' => null,
                'metadata_json' => [
                    'operation_id' => $operationId,
                    'operation_payload_hash' => $payloadHash,
                    'basis' => $reviewerId === null ? 'rule_pass' : 'reviewer',
                    'content_hash' => $evaluation['content_hash'],
                    'contract' => $evaluation['contract'],
                    'format' => $flow['adapter']->format(),
                    'check_version' => $check['version'],
                    'planner_version' => $evaluation['derived']['version'] ?? null,
                    'planning_revision' => $stage->planning_revision,
                    'selection_version' => $version + 1,
                ],
            ]);

            $metadata = $project->metadata_json ?? [];
            $metadata[self::SELECTION_KEY] = [
                'stage_id' => (string) $stage->id,
                'content_hash' => $evaluation['content_hash'],
                'approval_id' => (string) $decision->id,
                'version' => $version + 1,
                'approved_at' => now()->toIso8601String(),
            ];
            $project->metadata_json = $metadata;
            $project->save();

            return [$decision, 'approved'];
        });
    }

    /**
     * @return array{stage_id: string, document: array<string, mixed>, plan: array<string, mixed>, token: array{version: string, stage_id: string, content_hash: string, selection_version: int, source_fingerprint: string}} the approved scene plan the shot step takes
     *
     * @throws RuntimeException when no scene plan is approved, it no longer matches its approval or its sources changed
     */
    public function approvedSource(string $projectId): array
    {
        $flow = $this->workflowFor($projectId);
        $selection = $this->selection($projectId) ?? throw new RuntimeException('Dự án chưa có bản chia scene đã duyệt.');
        $stage = $this->sceneStage($projectId, (string) ($selection['stage_id'] ?? '')) ?? throw new RuntimeException('Bản chia scene đã duyệt không còn hoặc không thuộc dự án.');
        $decision = $this->decisions->findApprovalFor($projectId, $selection['approval_id'] ?? null, self::ENTITY_TYPE, $stage->id);

        if ($decision === null) {
            throw new RuntimeException('Quyết định duyệt bản chia scene không hợp lệ.');
        }

        [$evaluation, $problem] = $this->evaluate($stage, $flow['adapter']);

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        if (! hash_equals((string) $evaluation['content_hash'], (string) ($selection['content_hash'] ?? ''))) {
            throw new RuntimeException('Nội dung bản chia scene không khớp bản đã duyệt.');
        }

        // The next step reads this scene plan only when every condition it needs still holds — before any paid call.
        MotoCheckRules::requireUsable((array) $evaluation['check'], 'Bản chia scene đã duyệt');

        $source = $this->source($stage);

        if (($problem = $flow['adapter']->sourceProblem($projectId, $source)) !== null) {
            throw new RuntimeException('Bản chia scene đã duyệt cần cập nhật: '.$problem);
        }

        return [
            'stage_id' => (string) $stage->id,
            'document' => $evaluation['document'],
            'plan' => (array) $evaluation['derived'],
            'token' => [
                'version' => self::TOKEN_VERSION,
                'stage_id' => (string) $stage->id,
                'content_hash' => (string) $evaluation['content_hash'],
                'selection_version' => (int) $selection['version'],
                'source_fingerprint' => CanonicalJson::hash($source),
            ],
        ];
    }

    /** @return array{0: array<string, mixed>, 1: ?string} */
    private function evaluate(VideoPlanningStage $stage, SceneWorkflow $adapter): array
    {
        $meta = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null);
        $output = is_array($stage->output_json) ? $stage->output_json : [];

        if (! is_array($meta['manifest'] ?? null) || ! is_array($meta['context'] ?? null) || ! is_array($meta['source'] ?? null)) {
            return [[], 'Bản chia scene này không lưu đủ manifest, ngữ cảnh kiểm và nguồn của lượt chạy.'];
        }

        $format = is_string($meta['context']['format'] ?? null) ? $meta['context']['format'] : $adapter->format();

        if (($output['format'] ?? null) !== $format) {
            return [[], 'Bản chia scene này theo định dạng '.json_encode($output['format'] ?? null).', lượt chạy của nó ghim '.$format.'.'];
        }

        try {
            $evaluation = $this->evaluation->of((string) $stage->raw_response, $meta['manifest'], $meta['context'], $meta['source'], $adapter);
        } catch (RuntimeException $e) {
            return [[], 'Không kiểm lại được bản chia scene theo hợp đồng của nó: '.$e->getMessage()];
        }

        if ($evaluation['content_hash'] === null || ! hash_equals($evaluation['content_hash'], (string) ($output['content_hash'] ?? ''))) {
            return [[], 'Nội dung bản chia scene đã lưu không khớp hash của nó — không dùng được.'];
        }

        if (! $adapter instanceof MotoScenes) {
            return [$evaluation, null];
        }

        $stored = $output['derived'] ?? null;
        $problem = self::derivedProblem($stored, $evaluation['derived'] ?? null);

        if ($problem === null) {
            return [['derived_problem' => null] + $evaluation, null];
        }

        $hash = $output[self::DERIVED_HASH_KEY] ?? null;

        if (! is_string($hash) || ! hash_equals($hash, self::derivedHash((array) $stored))) {
            return [[], 'Bản dẫn xuất đã lưu của bản chia scene khác bản tính lại từ nội dung của nó và '.(is_string($hash) ? 'không khớp mã băm lưu cùng lượt chạy' : 'lượt chạy không lưu mã băm để xác minh').' — không dùng được; chia lại scene.'];
        }

        return [['derived' => $stored, 'derived_problem' => $problem] + $evaluation, null];
    }

    /** @var string the key of a scene run's output that holds the hash of the derived plan stored with it */
    public const DERIVED_HASH_KEY = 'derived_hash';

    /**
     * @param  array<string, mixed>  $derived  the derived plan of a scene run
     * @return string the hash stored beside it, by which a stored plan that differs from the one derived now is known to be the plan the run stored
     */
    public static function derivedHash(array $derived): string
    {
        return CanonicalJson::hash(['derived' => $derived]);
    }

    /**
     * @param  mixed  $stored  the derived plan stored with the run
     * @param  mixed  $derived  the plan derived again from the run now
     * @return ?string why the plan derived now is not the plan stored with the run, null when the run stored none or both carry the same content. The planner version label and a key the stored plan does not have are no change of its content. A stored plan that differs is never replaced by the one derived now: it is kept only when the hash stored with the run proves it is the plan the run stored, and is an integrity error otherwise
     */
    private static function derivedProblem(mixed $stored, mixed $derived): ?string
    {
        if (! is_array($stored) || ! is_array($derived)) {
            return null;
        }

        $kept = array_diff_key($stored, ['version' => true]);

        if (CanonicalJson::hash($kept) === CanonicalJson::hash(array_intersect_key($derived, $kept))) {
            return null;
        }

        return 'Bản dẫn xuất tính lại bằng '.($derived['version'] ?? '—').' khác bản đã lưu lúc chạy ('.($stored['version'] ?? '—').') — hệ thống giữ bản đã lưu, không tự thay; chia lại scene để có bản theo bộ dẫn xuất mới.';
    }
}
