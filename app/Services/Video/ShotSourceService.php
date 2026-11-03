<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoReviewDecision;
use App\Video\Script\ScriptEvaluation;
use App\Video\Shots\ShotWorkflow;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ShotSourceService
{
    public const ENTITY_TYPE = 'shots';

    public const SELECTION_KEY = 'shots_selection';

    public const TOKEN_VERSION = 'shots-source-token-v1';

    /** @return array{workflow: string, action: string, adapter: ShotWorkflow, category_id: string, category_slug: string} */
    public function workflowFor(string $projectId): array
    {
        $project = VideoProject::query()->with('article.category')->find($projectId) ?? throw new RuntimeException('Không tìm thấy dự án.');
        $category = $project->article?->category ?? throw new RuntimeException('Dự án không gắn bài viết có chủ đề — Phase 7 không áp dụng.');
        $workflow = (string) (config('video.superyacht.contracts.categories')[$category->slug] ?? '');
        $entry = $workflow === '' ? null : (config('video.shots.workflows')[$workflow] ?? null);

        if (! is_array($entry)) {
            throw new RuntimeException("Chủ đề {$category->slug} chưa hỗ trợ Phase 7 (workflow ".($workflow === '' ? 'chưa cấu hình' : $workflow).').');
        }

        $adapter = app((string) $entry['adapter']);

        if (! $adapter instanceof ShotWorkflow) {
            throw new RuntimeException("Adapter Phase 7 của workflow {$workflow} không hợp lệ.");
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

    public function shotStage(string $projectId, string $stageId): ?VideoPlanningStage
    {
        return VideoPlanningStage::query()
            ->whereKey($stageId)
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::SHOTS->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->whereNull('input_json->orphan_of_stage_id')
            ->first();
    }

    /** @return ?array{stage_id: string, content_hash: string, approval_id: string, version: int, approved_at: string} */
    public function selection(string $projectId): ?array
    {
        $selection = VideoProject::query()->find($projectId)?->metadata_json[self::SELECTION_KEY] ?? null;

        return is_array($selection) ? $selection : null;
    }

    /** @return array{0: array<string, mixed>, 1: ?string} a shot stage re-evaluated from its raw answer and pinned contract, or why it cannot be trusted */
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

            $stage = $this->shotStage($projectId, $stageId);

            if ($stage === null) {
                return [null, 'Kế hoạch shot này không thuộc dự án hoặc chưa chạy thành công.'];
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
                return [null, 'Lựa chọn kế hoạch shot đã đổi ở một tab khác — tải lại trang rồi duyệt lại.'];
            }

            $check = $evaluation['check'];

            if ($check['verdict'] !== 'PASS') {
                return [null, 'Kế hoạch shot này chưa đạt kiểm RULE — không duyệt được.'];
            }

            if ($check['status'] !== 'ok') {
                return [null, 'Model trả status '.json_encode($check['status']).' — còn xung đột chặn; bản này chỉ để xem.'];
            }

            if ($evaluation['derived_problem'] !== null) {
                return [null, $evaluation['derived_problem']];
            }

            if (($problem = $flow['adapter']->sourceProblem($projectId, $this->source($stage))) !== null) {
                return [null, 'Nguồn của kế hoạch shot cần cập nhật: '.$problem];
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
     * @return array{stage_id: string, document: array<string, mixed>, plan: array<string, mixed>, token: array{version: string, stage_id: string, content_hash: string, selection_version: int, source_fingerprint: string}} the approved shot list the render step takes
     *
     * @throws RuntimeException when no shot list is approved, it no longer matches its approval, its plan derived now differs from the stored one or its scene plan changed
     */
    public function approvedSource(string $projectId): array
    {
        $flow = $this->workflowFor($projectId);
        $selection = $this->selection($projectId) ?? throw new RuntimeException('Dự án chưa có kế hoạch shot đã duyệt.');
        $stage = $this->shotStage($projectId, (string) ($selection['stage_id'] ?? '')) ?? throw new RuntimeException('Kế hoạch shot đã duyệt không còn hoặc không thuộc dự án.');
        $decision = VideoReviewDecision::query()->whereKey($selection['approval_id'] ?? null)->where('project_id', $projectId)
            ->where('entity_type', self::ENTITY_TYPE)->where('entity_id', $stage->id)->where('decision', 'approved')->first();

        if ($decision === null) {
            throw new RuntimeException('Quyết định duyệt kế hoạch shot không hợp lệ.');
        }

        [$evaluation, $problem] = $this->evaluate($stage, $flow['adapter']);

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        if (! hash_equals((string) $evaluation['content_hash'], (string) ($selection['content_hash'] ?? ''))) {
            throw new RuntimeException('Nội dung kế hoạch shot không khớp bản đã duyệt.');
        }

        if ($evaluation['derived_problem'] !== null) {
            throw new RuntimeException($evaluation['derived_problem']);
        }

        $source = $this->source($stage);

        if (($problem = $flow['adapter']->sourceProblem($projectId, $source)) !== null) {
            throw new RuntimeException('Kế hoạch shot đã duyệt cần cập nhật: '.$problem);
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

    /** @return array{0: array<string, mixed>, 1: ?string} the evaluation with derived_problem, ShotSourceService::derivedProblem(), or why the stage cannot be evaluated */
    private function evaluate(VideoPlanningStage $stage, ShotWorkflow $adapter): array
    {
        $meta = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null);
        $output = is_array($stage->output_json) ? $stage->output_json : [];

        if (! is_array($meta['manifest'] ?? null) || ! is_array($meta['context'] ?? null) || ! is_array($meta['source'] ?? null)) {
            return [[], 'Kế hoạch shot này không lưu đủ manifest, ngữ cảnh kiểm và nguồn của lượt chạy.'];
        }

        if (($output['format'] ?? null) !== $adapter->format()) {
            return [[], 'Kế hoạch shot này theo định dạng '.json_encode($output['format'] ?? null).', bước này chỉ hỗ trợ '.$adapter->format().'.'];
        }

        try {
            if (($problem = $adapter->contextProblem($meta['context'], ScriptEvaluation::contract($meta['manifest']))) !== null) {
                return [[], 'Kế hoạch shot này ghim ngữ cảnh kiểm không khớp hợp đồng của nó: '.$problem.'.'];
            }

            $evaluation = ScriptEvaluation::of((string) $stage->raw_response, $meta['manifest'], $meta['context'], $meta['source'], $adapter);
        } catch (RuntimeException $e) {
            return [[], 'Không kiểm lại được kế hoạch shot theo hợp đồng của nó: '.$e->getMessage()];
        }

        if ($evaluation['content_hash'] === null || ! hash_equals($evaluation['content_hash'], (string) ($output['content_hash'] ?? ''))) {
            return [[], 'Nội dung kế hoạch shot đã lưu không khớp hash của nó — không dùng được.'];
        }

        return [$evaluation + ['derived_problem' => self::derivedProblem($output['derived'] ?? null, $evaluation['derived'])], null];
    }

    /**
     * @param  mixed  $stored  the derived plan stored with the run
     * @param  ?array<string, mixed>  $derived  the plan derived again from the run now
     * @return ?string why the plan derived now is not the plan stored with the run, null when both carry the same content; only the planner version label may differ, so a newer planner never changes a stored plan unnoticed
     */
    private static function derivedProblem(mixed $stored, ?array $derived): ?string
    {
        $content = static fn (mixed $plan): array => ['plan' => is_array($plan) ? array_diff_key($plan, ['version' => true]) : $plan];

        if (CanonicalJson::hash($content($stored)) === CanonicalJson::hash($content($derived))) {
            return null;
        }

        return 'Kế hoạch dẫn xuất tính lại bằng '.($derived['version'] ?? '—').' khác bản đã lưu lúc chạy ('.(is_array($stored) ? ($stored['version'] ?? '—') : '—').') — bản này chỉ để xem; lập lại kế hoạch shot để có bản dùng được.';
    }
}
