<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoReviewDecision;
use App\Video\Script\ScriptEvaluation;
use App\Video\Script\ScriptWorkflow;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ScriptSourceService
{
    public const ENTITY_TYPE = 'script';

    public const SELECTION_KEY = 'script_selection';

    public const TOKEN_VERSION = 'script-source-token-v1';

    /** @return array{workflow: string, action: string, adapter: ScriptWorkflow, category_id: string, category_slug: string} */
    public function workflowFor(string $projectId): array
    {
        $project = VideoProject::query()->with('article.category')->find($projectId) ?? throw new RuntimeException('Không tìm thấy dự án.');
        $category = $project->article?->category ?? throw new RuntimeException('Dự án không gắn bài viết có chủ đề — bước Kịch bản không áp dụng.');
        $workflow = (string) (config('video.superyacht.contracts.categories')[$category->slug] ?? '');
        $entry = $workflow === '' ? null : (config('video.script.workflows')[$workflow] ?? null);

        if (! is_array($entry)) {
            throw new RuntimeException("Chủ đề {$category->slug} chưa hỗ trợ bước Kịch bản (workflow ".($workflow === '' ? 'chưa cấu hình' : $workflow).').');
        }

        $adapter = app((string) $entry['adapter']);

        if (! $adapter instanceof ScriptWorkflow) {
            throw new RuntimeException("Adapter kịch bản của workflow {$workflow} không hợp lệ.");
        }

        return ['workflow' => $workflow, 'action' => (string) $entry['action'], 'adapter' => $adapter, 'category_id' => (string) $category->id, 'category_slug' => (string) $category->slug];
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

            $payloadHash = hash('sha256', json_encode([
                'project_id' => $projectId,
                'stage_id' => $stageId,
                'decision' => 'approved',
                'content_hash' => $expectedHash,
                'selection_version' => $expectedVersion,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $replayed = VideoReviewDecision::query()
                ->where('project_id', $projectId)
                ->where('entity_type', self::ENTITY_TYPE)
                ->where('metadata_json->operation_id', $operationId)
                ->first();

            if ($replayed !== null) {
                return hash_equals((string) ($replayed->metadata_json['operation_payload_hash'] ?? ''), $payloadHash)
                    ? [$replayed, 'replayed']
                    : [null, 'Mã thao tác này đã dùng cho một lần duyệt khác — tải lại trang.'];
            }

            $stage = $this->scriptStage($projectId, $stageId);

            if ($stage === null) {
                return [null, 'Bản kịch bản này không thuộc dự án hoặc chưa chạy thành công.'];
            }

            [$evaluation, $problem] = $this->evaluate($stage, $flow['adapter']);

            if ($problem !== null) {
                return [null, $problem];
            }

            if (! hash_equals($evaluation['content_hash'], $expectedHash)) {
                return [null, 'Trang đang hiển thị một nội dung khác bản đã lưu — tải lại trang.'];
            }

            $selection = (array) ($project->metadata_json[self::SELECTION_KEY] ?? []);
            $version = (int) ($selection['version'] ?? 0);

            if ($version !== $expectedVersion) {
                return [null, 'Lựa chọn kịch bản đã đổi ở một tab khác — tải lại trang rồi duyệt lại.'];
            }

            $check = $evaluation['check'];

            if ($check['verdict'] !== 'PASS') {
                return [null, 'Bản kịch bản này chưa đạt kiểm RULE — không duyệt được.'];
            }

            if ($check['status'] !== 'ok') {
                return [null, 'Model trả status '.json_encode($check['status']).' — bản này chỉ để xem; viết lại để có bản ok.'];
            }

            $meta = PlanningStageStore::metadataOf($stage->input_json);

            if (($problem = $flow['adapter']->sourceProblem($projectId, (array) ($meta['source'] ?? []))) !== null) {
                return [null, 'Nguồn của bản kịch bản không còn hợp lệ: '.$problem];
            }

            $revision = 1 + (int) VideoReviewDecision::query()->where('entity_type', self::ENTITY_TYPE)->where('entity_id', $stageId)->max('revision');
            $decision = VideoReviewDecision::create([
                'project_id' => $projectId,
                'session_id' => null,
                'entity_type' => self::ENTITY_TYPE,
                'entity_id' => $stageId,
                'decision' => 'approved',
                'revision' => $revision,
                'reviewer_id' => $reviewerId,
                'reason' => null,
                'metadata_json' => [
                    'operation_id' => $operationId,
                    'operation_payload_hash' => $payloadHash,
                    'content_hash' => $evaluation['content_hash'],
                    'contract' => $evaluation['contract'],
                    'format' => $flow['adapter']->format(),
                    'check_version' => $check['version'],
                    'judge' => $check['judge'],
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
                'selected_at' => now()->toIso8601String(),
            ];
            $project->metadata_json = $metadata;
            $project->save();

            return [$decision, 'approved'];
        });
    }

    /**
     * @return array{stage_id: string, document: array<string, mixed>, derived: array<string, mixed>, token: array{version: string, stage_id: string, content_hash: string, selection_version: int, source_fingerprint: string}}
     *
     * @throws RuntimeException when there is no usable approved script
     */
    public function approvedSource(string $projectId): array
    {
        $flow = $this->workflowFor($projectId);
        $project = VideoProject::query()->findOrFail($projectId);
        $selection = $project->metadata_json[self::SELECTION_KEY] ?? null;

        if (! is_array($selection) || ! is_string($selection['stage_id'] ?? null)) {
            throw new RuntimeException('Dự án chưa có kịch bản đã duyệt và chọn.');
        }

        $stage = $this->scriptStage($projectId, $selection['stage_id']) ?? throw new RuntimeException('Bản kịch bản đang chọn không còn hoặc không thuộc dự án.');
        $decision = VideoReviewDecision::query()->whereKey($selection['approval_id'] ?? null)->where('project_id', $projectId)
            ->where('entity_type', self::ENTITY_TYPE)->where('entity_id', $stage->id)->first();
        $latest = VideoReviewDecision::query()->where('entity_type', self::ENTITY_TYPE)->where('entity_id', $stage->id)->orderByDesc('revision')->first();

        if ($decision === null || $decision->decision !== 'approved' || $latest?->id !== $decision->id) {
            throw new RuntimeException('Quyết định duyệt của bản kịch bản đang chọn không hợp lệ.');
        }

        [$evaluation, $problem] = $this->evaluate($stage, $flow['adapter']);

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        if (! hash_equals($evaluation['content_hash'], (string) ($selection['content_hash'] ?? '')) || ! hash_equals($evaluation['content_hash'], (string) ($decision->metadata_json['content_hash'] ?? ''))) {
            throw new RuntimeException('Nội dung bản kịch bản đang chọn không khớp bản đã duyệt.');
        }

        if ($evaluation['derived'] === null || $evaluation['derived'] != ($stage->output_json['derived'] ?? null)) {
            throw new RuntimeException('Dữ liệu dẫn xuất (reveal_plan, emotion_curve) không khớp nội dung đã duyệt.');
        }

        $meta = PlanningStageStore::metadataOf($stage->input_json);
        $source = (array) ($meta['source'] ?? []);

        if (($problem = $flow['adapter']->sourceProblem($projectId, $source)) !== null) {
            throw new RuntimeException('Nguồn của kịch bản đang chọn không còn hợp lệ: '.$problem);
        }

        return [
            'stage_id' => (string) $stage->id,
            'document' => $evaluation['document'],
            'derived' => $evaluation['derived'],
            'token' => [
                'version' => self::TOKEN_VERSION,
                'stage_id' => (string) $stage->id,
                'content_hash' => $evaluation['content_hash'],
                'selection_version' => (int) $selection['version'],
                'source_fingerprint' => CanonicalJson::hash($source),
            ],
        ];
    }

    /** @return ?array{stage_id: string, content_hash: string, approval_id: string, version: int, selected_at: string} */
    public function selection(string $projectId): ?array
    {
        $selection = VideoProject::query()->find($projectId)?->metadata_json[self::SELECTION_KEY] ?? null;

        return is_array($selection) ? $selection : null;
    }

    /** @return array{0: array<string, mixed>, 1: ?string} the stage re-evaluated from its raw answer and pinned contract, as approval sees it, or why it cannot be trusted */
    public function inspect(string $projectId, VideoPlanningStage $stage): array
    {
        try {
            $flow = $this->workflowFor($projectId);
        } catch (RuntimeException $e) {
            return [[], $e->getMessage()];
        }

        return $this->evaluate($stage, $flow['adapter']);
    }

    public function scriptStage(string $projectId, string $stageId): ?VideoPlanningStage
    {
        return VideoPlanningStage::query()
            ->whereKey($stageId)
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::SCRIPT->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->whereNull('input_json->orphan_of_stage_id')
            ->first();
    }

    /** @return array{0: array<string, mixed>, 1: ?string} */
    private function evaluate(VideoPlanningStage $stage, ScriptWorkflow $adapter): array
    {
        $meta = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null);
        $output = is_array($stage->output_json) ? $stage->output_json : [];

        if (! is_array($meta['manifest'] ?? null) || ! is_array($meta['context'] ?? null) || ! is_array($meta['source'] ?? null)) {
            return [[], 'Bản kịch bản này không lưu đủ manifest, ngữ cảnh kiểm và nguồn của lượt chạy.'];
        }

        if (($output['format'] ?? null) !== $adapter->format()) {
            return [[], 'Bản kịch bản này theo định dạng '.json_encode($output['format'] ?? null).', bước Kịch bản chỉ hỗ trợ '.$adapter->format().'.'];
        }

        try {
            $evaluation = ScriptEvaluation::of((string) $stage->raw_response, $meta['manifest'], $meta['context'], $meta['source'], $adapter);
        } catch (RuntimeException $e) {
            return [[], 'Không kiểm lại được bản kịch bản theo hợp đồng của nó: '.$e->getMessage()];
        }

        if ($evaluation['content_hash'] === null || ! hash_equals($evaluation['content_hash'], (string) ($output['content_hash'] ?? ''))) {
            return [[], 'Nội dung bản kịch bản đã lưu không khớp hash của nó — không dùng được.'];
        }

        return [$evaluation, null];
    }
}
