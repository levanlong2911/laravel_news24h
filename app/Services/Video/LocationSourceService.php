<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoReviewDecision;
use App\Video\Locations\LocationReferencePrompt;
use App\Video\Locations\LocationWorkflow;
use App\Video\Script\ScriptEvaluation;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class LocationSourceService
{
    public const TEXT_ENTITY = 'locations_text';

    public const SET_ENTITY = 'locations_set';

    public const SELECTION_KEY = 'locations_selection';

    public const TOKEN_VERSION = 'locations-source-token-v1';

    /** @return array{workflow: string, action: string, adapter: LocationWorkflow, category_id: string, category_slug: string} */
    public function workflowFor(string $projectId): array
    {
        $project = VideoProject::query()->with('article.category')->find($projectId) ?? throw new RuntimeException('Không tìm thấy dự án.');
        $category = $project->article?->category ?? throw new RuntimeException('Dự án không gắn bài viết có chủ đề — Phase 5 không áp dụng.');
        $workflow = (string) (config('video.superyacht.contracts.categories')[$category->slug] ?? '');
        $entry = $workflow === '' ? null : (config('video.locations.workflows')[$workflow] ?? null);

        if (! is_array($entry)) {
            throw new RuntimeException("Chủ đề {$category->slug} chưa hỗ trợ Phase 5 (workflow ".($workflow === '' ? 'chưa cấu hình' : $workflow).').');
        }

        $adapter = app((string) $entry['adapter']);

        if (! $adapter instanceof LocationWorkflow) {
            throw new RuntimeException("Adapter Phase 5 của workflow {$workflow} không hợp lệ.");
        }

        return ['workflow' => $workflow, 'action' => (string) $entry['action'], 'adapter' => $adapter, 'category_id' => (string) $category->id, 'category_slug' => (string) $category->slug];
    }

    /** @return array{version: int, text: ?array<string, mixed>, images: array<string, array<string, mixed>>, set: ?array<string, mixed>} */
    public function selection(string $projectId): array
    {
        return $this->selectionOf(VideoProject::query()->find($projectId) ?? new VideoProject);
    }

    public function locationStage(string $projectId, string $stageId): ?VideoPlanningStage
    {
        return VideoPlanningStage::query()
            ->whereKey($stageId)
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::LOCATIONS->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->whereNull('input_json->orphan_of_stage_id')
            ->first();
    }

    /** @return array{0: array<string, mixed>, 1: ?string} the stage re-evaluated from its raw answer and pinned contract, or why it cannot be trusted */
    public function inspect(string $projectId, VideoPlanningStage $stage): array
    {
        try {
            $flow = $this->workflowFor($projectId);
        } catch (RuntimeException $e) {
            return [[], $e->getMessage()];
        }

        return $this->evaluate($stage, $flow['adapter']);
    }

    /** @return array{0: ?VideoReviewDecision, 1: string} */
    public function approveText(string $projectId, string $stageId, string $expectedHash, int $expectedVersion, string $operationId, ?string $reviewerId): array
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

            $payloadHash = hash('sha256', json_encode([$projectId, $stageId, $expectedHash, $expectedVersion], JSON_THROW_ON_ERROR));
            $replayed = $this->replayed($projectId, self::TEXT_ENTITY, $operationId, $payloadHash);

            if ($replayed !== null) {
                return $replayed;
            }

            $stage = $this->locationStage($projectId, $stageId);

            if ($stage === null) {
                return [null, 'Bản mô tả này không thuộc dự án hoặc chưa chạy thành công.'];
            }

            [$evaluation, $problem] = $this->evaluate($stage, $flow['adapter']);

            if ($problem !== null) {
                return [null, $problem];
            }

            if (! hash_equals((string) $evaluation['content_hash'], $expectedHash)) {
                return [null, 'Trang đang hiển thị một nội dung khác bản đã lưu — tải lại trang.'];
            }

            $selection = $this->selectionOf($project);

            if ($selection['version'] !== $expectedVersion) {
                return [null, 'Bộ bối cảnh đã đổi ở một tab khác — tải lại trang rồi duyệt lại.'];
            }

            $check = $evaluation['check'];

            if ($check['verdict'] !== 'PASS') {
                return [null, 'Bản mô tả chưa đạt kiểm RULE — không duyệt được.'];
            }

            if ($check['status'] !== 'ok') {
                return [null, 'Model trả status '.json_encode($check['status']).' — bản này chỉ để xem; viết lại để có bản ok.'];
            }

            if (($problem = $flow['adapter']->sourceProblem($projectId, $this->source($stage))) !== null) {
                return [null, 'Nguồn của bản mô tả không còn hợp lệ: '.$problem];
            }

            $decision = $this->decide($projectId, self::TEXT_ENTITY, (string) $stage->id, $reviewerId, [
                'operation_id' => $operationId,
                'operation_payload_hash' => $payloadHash,
                'content_hash' => $evaluation['content_hash'],
                'contract' => $evaluation['contract'],
                'check_version' => $check['version'],
                'planner_version' => $evaluation['derived']['version'] ?? null,
                'planning_revision' => $stage->planning_revision,
                'selection_version' => $selection['version'] + 1,
            ]);
            $sameText = ($selection['text']['stage_id'] ?? null) === (string) $stage->id;
            $this->store($project, [
                'version' => $selection['version'] + 1,
                'text' => ['stage_id' => (string) $stage->id, 'content_hash' => $evaluation['content_hash'], 'approval_id' => (string) $decision->id, 'approved_at' => now()->toIso8601String()],
                'images' => $sameText ? $selection['images'] : [],
                'set' => null,
            ]);

            return [$decision, 'approved'];
        });
    }

    /**
     * @return array{stage: VideoPlanningStage, evaluation: array<string, mixed>, flow: array<string, mixed>, selection: array<string, mixed>}
     *
     * @throws RuntimeException when no description is approved or it no longer serves
     */
    public function approvedText(string $projectId): array
    {
        $flow = $this->workflowFor($projectId);
        $selection = $this->selection($projectId);
        $text = $selection['text'] ?? throw new RuntimeException('Chưa duyệt bản mô tả bối cảnh nào.');
        $stage = $this->locationStage($projectId, (string) ($text['stage_id'] ?? '')) ?? throw new RuntimeException('Bản mô tả đã duyệt không còn hoặc không thuộc dự án.');
        $decision = VideoReviewDecision::query()->whereKey($text['approval_id'] ?? null)->where('project_id', $projectId)
            ->where('entity_type', self::TEXT_ENTITY)->where('entity_id', $stage->id)->where('decision', 'approved')->first();

        if ($decision === null) {
            throw new RuntimeException('Quyết định duyệt bản mô tả không hợp lệ.');
        }

        [$evaluation, $problem] = $this->evaluate($stage, $flow['adapter']);

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        if (! hash_equals((string) $evaluation['content_hash'], (string) ($text['content_hash'] ?? ''))) {
            throw new RuntimeException('Nội dung bản mô tả không khớp bản đã duyệt.');
        }

        if (($problem = $flow['adapter']->sourceProblem($projectId, $this->source($stage))) !== null) {
            throw new RuntimeException('Nguồn của bản mô tả đã duyệt không còn hợp lệ: '.$problem);
        }

        return ['stage' => $stage, 'evaluation' => $evaluation, 'flow' => $flow, 'selection' => $selection];
    }

    /** @return array{0: bool, 1: string} */
    public function choose(string $projectId, string $requestId, string $artifactId, int $expectedVersion): array
    {
        try {
            $text = $this->approvedText($projectId);
        } catch (RuntimeException $e) {
            return [false, $e->getMessage()];
        }

        $request = $this->request($text, $requestId);

        if ($request === null) {
            return [false, "Bản mô tả đã duyệt không có yêu cầu ảnh {$requestId}."];
        }

        return DB::transaction(function () use ($projectId, $requestId, $artifactId, $expectedVersion, $text): array {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $selection = $this->selectionOf($project);

            if ($selection['version'] !== $expectedVersion) {
                return [false, 'Bộ bối cảnh đã đổi ở một tab khác — tải lại trang rồi chọn lại.'];
            }

            $artifact = VideoArtifact::query()->whereKey($artifactId)->where('project_id', $projectId)->first();
            $image = $artifact === null ? null : VideoDesignImage::query()->whereKey($artifact->design_image_id)->where('project_id', $projectId)
                ->where('image_type', DesignImageStore::LOCATION_REFERENCE_TYPE)->first();
            $spec = (array) ($image?->prompt_spec_json ?? []);

            if ($image === null || ($spec['locations_stage_id'] ?? null) !== (string) $text['stage']->id || ($spec['request_id'] ?? null) !== $requestId) {
                return [false, 'Ảnh này không thuộc yêu cầu ảnh hoặc bản mô tả đang duyệt.'];
            }

            if (! in_array($image->status, [DesignImageStatus::RENDERED->value, DesignImageStatus::APPROVED->value], true)) {
                return [false, 'Ảnh này chưa render xong.'];
            }

            if (($problem = self::fileProblem($artifact, (string) $artifact->sha256)) !== null) {
                return [false, $problem];
            }

            $images = $selection['images'];
            $images[$requestId] = ['image_id' => (string) $image->id, 'artifact_id' => (string) $artifact->id, 'sha256' => (string) $artifact->sha256, 'chosen_at' => now()->toIso8601String()];
            $this->store($project, ['version' => $selection['version'] + 1, 'images' => $images, 'set' => null] + $selection);

            return [true, 'ok'];
        });
    }

    /**
     * @param  array{stage: VideoPlanningStage, evaluation: array<string, mixed>, flow: array<string, mixed>, selection: array<string, mixed>}  $text
     * @return list<array<string, mixed>> every request of the approved plan with what is chosen for it and why it is not ready yet
     */
    public function manifest(array $text): array
    {
        $plan = (array) $text['evaluation']['derived'];
        $chosen = (array) $text['selection']['images'];
        $rows = [];

        foreach ((array) $plan['requests'] as $request) {
            $id = (string) $request['id'];
            $pick = $chosen[$id] ?? null;
            $problem = null;

            if ($pick === null) {
                $problem = $request['required'] ? 'chưa chọn ảnh' : null;
            } else {
                $artifact = VideoArtifact::query()->whereKey($pick['artifact_id'] ?? null)->where('project_id', $text['stage']->project_id)->first();
                $problem = $artifact === null ? 'ảnh đã chọn không còn' : self::fileProblem($artifact, (string) ($pick['sha256'] ?? ''));
                $problem ??= $this->staleInputs($request, $pick, $chosen);
            }

            $rows[] = ['request' => $request, 'chosen' => $pick, 'problem' => $problem];
        }

        return $rows;
    }

    /** @return array{0: ?VideoReviewDecision, 1: string} */
    public function approveSet(string $projectId, int $expectedVersion, string $operationId, ?string $reviewerId): array
    {
        if (! Str::isUuid($operationId)) {
            return [null, 'Mã thao tác duyệt không hợp lệ — tải lại trang.'];
        }

        try {
            $text = $this->approvedText($projectId);
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }

        return DB::transaction(function () use ($projectId, $expectedVersion, $operationId, $reviewerId, $text): array {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $selection = $this->selectionOf($project);
            $payloadHash = hash('sha256', json_encode([$projectId, $expectedVersion], JSON_THROW_ON_ERROR));
            $replayed = $this->replayed($projectId, self::SET_ENTITY, $operationId, $payloadHash);

            if ($replayed !== null) {
                return $replayed;
            }

            if ($selection['version'] !== $expectedVersion) {
                return [null, 'Bộ bối cảnh đã đổi ở một tab khác — tải lại trang rồi duyệt lại.'];
            }

            $text['selection'] = $selection;
            $rows = $this->manifest($text);
            $missing = array_values(array_filter($rows, static fn (array $row): bool => $row['problem'] !== null));

            if ($missing !== []) {
                return [null, 'Chưa duyệt được bộ ảnh: '.implode('; ', array_map(static fn (array $row): string => $row['request']['id'].' '.$row['problem'], $missing)).'.'];
            }

            $manifest = self::handoff($rows);
            $decision = $this->decide($projectId, self::SET_ENTITY, (string) $text['stage']->id, $reviewerId, [
                'operation_id' => $operationId,
                'operation_payload_hash' => $payloadHash,
                'text_approval_id' => $selection['text']['approval_id'],
                'content_hash' => $selection['text']['content_hash'],
                'manifest' => $manifest,
                'manifest_hash' => CanonicalJson::hash($manifest),
                'vision' => 'not_run',
                'manual_review' => 'approved',
                'selection_version' => $selection['version'] + 1,
            ]);
            $this->store($project, ['version' => $selection['version'] + 1, 'set' => ['approval_id' => (string) $decision->id, 'manifest_hash' => CanonicalJson::hash($manifest), 'approved_at' => now()->toIso8601String()]] + $selection);

            return [$decision, 'approved'];
        });
    }

    /**
     * @return array{stage_id: string, document: array<string, mixed>, bindings: list<array<string, mixed>>, plan: array<string, mixed>, manifest: list<array<string, mixed>>, review: array{vision: string, manual_review: string}, token: array<string, mixed>}
     *
     * @throws RuntimeException when there is no complete approved Phase 5 set
     */
    public function approvedSource(string $projectId): array
    {
        $text = $this->approvedText($projectId);
        $selection = $text['selection'];
        $set = $selection['set'] ?? throw new RuntimeException('Bộ ảnh Phase 5 chưa được duyệt.');
        $decision = VideoReviewDecision::query()->whereKey($set['approval_id'] ?? null)->where('project_id', $projectId)
            ->where('entity_type', self::SET_ENTITY)->where('entity_id', $text['stage']->id)->where('decision', 'approved')->first();

        if ($decision === null || ($decision->metadata_json['text_approval_id'] ?? null) !== ($selection['text']['approval_id'] ?? null)) {
            throw new RuntimeException('Quyết định duyệt bộ ảnh Phase 5 không hợp lệ.');
        }

        $rows = $this->manifest($text);

        foreach ($rows as $row) {
            if ($row['problem'] !== null) {
                throw new RuntimeException("Bộ ảnh Phase 5 không còn đủ: {$row['request']['id']} {$row['problem']}.");
            }
        }

        $manifest = self::handoff($rows);

        if (! hash_equals((string) ($set['manifest_hash'] ?? ''), CanonicalJson::hash($manifest)) || $decision->metadata_json['manifest_hash'] !== CanonicalJson::hash($manifest)) {
            throw new RuntimeException('Ảnh đã chọn không còn khớp bộ ảnh Phase 5 đã duyệt.');
        }

        $source = $this->source($text['stage']);

        return [
            'stage_id' => (string) $text['stage']->id,
            'document' => $text['evaluation']['document'],
            'bindings' => array_values((array) $text['evaluation']['document']['data']['bindings']),
            'plan' => $text['evaluation']['derived'],
            'manifest' => $manifest,
            'review' => ['vision' => (string) $decision->metadata_json['vision'], 'manual_review' => (string) $decision->metadata_json['manual_review']],
            'token' => [
                'version' => self::TOKEN_VERSION,
                'stage_id' => (string) $text['stage']->id,
                'content_hash' => (string) $text['evaluation']['content_hash'],
                'set_approval_id' => (string) $decision->id,
                'manifest_hash' => CanonicalJson::hash($manifest),
                'selection_version' => $selection['version'],
                'source_fingerprint' => CanonicalJson::hash($source),
            ],
        ];
    }

    /**
     * @param  array{stage: VideoPlanningStage, evaluation: array<string, mixed>}  $text
     * @return ?array<string, mixed> the plan row of a request of the approved description
     */
    public function request(array $text, string $requestId): ?array
    {
        foreach ((array) ($text['evaluation']['derived']['requests'] ?? []) as $request) {
            if ($request['id'] === $requestId) {
                return $request;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function source(VideoPlanningStage $stage): array
    {
        return (array) (PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)['source'] ?? []);
    }

    public static function fileProblem(VideoArtifact $artifact, string $sha256): ?string
    {
        $disk = Storage::disk((string) $artifact->storage_disk);

        if (! $disk->exists((string) $artifact->storage_path)) {
            return 'file ảnh không còn';
        }

        return $sha256 !== '' && hash_equals($sha256, hash('sha256', (string) $disk->get((string) $artifact->storage_path))) ? null : 'hash file ảnh không khớp';
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $pick
     * @param  array<string, array<string, mixed>>  $chosen
     */
    private function staleInputs(array $request, array $pick, array $chosen): ?string
    {
        $spec = (array) (VideoDesignImage::query()->find($pick['image_id'] ?? null)?->prompt_spec_json ?? []);
        $sources = (array) ($spec['sources'] ?? []);
        $position = 0;

        foreach ((array) $request['inputs'] as $input) {
            $used = $sources[$position++] ?? null;

            if ($input['kind'] === 'request' && ($used['artifact_id'] ?? null) !== ($chosen[$input['id']]['artifact_id'] ?? null)) {
                return "ảnh nguồn {$input['id']} đã đổi sau khi render — cần render lại hoặc đối chiếu";
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function handoff(array $rows): array
    {
        $manifest = [];

        foreach ($rows as $row) {
            if ($row['chosen'] === null) {
                continue;
            }

            $manifest[] = [
                'request_id' => $row['request']['id'],
                'space' => $row['request']['space'],
                'lighting' => $row['request']['lighting'],
                'view' => $row['request']['view'],
                'inputs' => $row['request']['inputs'],
                'required' => $row['request']['required'],
                'image_id' => (string) $row['chosen']['image_id'],
                'artifact_id' => (string) $row['chosen']['artifact_id'],
                'sha256' => (string) $row['chosen']['sha256'],
                'prompt_version' => LocationReferencePrompt::VERSION,
            ];
        }

        return $manifest;
    }

    /** @return array{0: array<string, mixed>, 1: ?string} */
    private function evaluate(VideoPlanningStage $stage, LocationWorkflow $adapter): array
    {
        $meta = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null);
        $output = is_array($stage->output_json) ? $stage->output_json : [];

        if (! is_array($meta['manifest'] ?? null) || ! is_array($meta['context'] ?? null) || ! is_array($meta['source'] ?? null)) {
            return [[], 'Bản mô tả này không lưu đủ manifest, ngữ cảnh kiểm và nguồn của lượt chạy.'];
        }

        if (($output['format'] ?? null) !== $adapter->format()) {
            return [[], 'Bản mô tả này theo định dạng '.json_encode($output['format'] ?? null).', Phase 5 chỉ hỗ trợ '.$adapter->format().'.'];
        }

        try {
            $evaluation = ScriptEvaluation::of((string) $stage->raw_response, $meta['manifest'], $meta['context'], $meta['source'], $adapter);
        } catch (RuntimeException $e) {
            return [[], 'Không kiểm lại được bản mô tả theo hợp đồng của nó: '.$e->getMessage()];
        }

        if ($evaluation['content_hash'] === null || ! hash_equals($evaluation['content_hash'], (string) ($output['content_hash'] ?? ''))) {
            return [[], 'Nội dung bản mô tả đã lưu không khớp hash của nó — không dùng được.'];
        }

        return [$evaluation, null];
    }

    /** @return ?array{0: ?VideoReviewDecision, 1: string} */
    private function replayed(string $projectId, string $entity, string $operationId, string $payloadHash): ?array
    {
        $replayed = VideoReviewDecision::query()->where('project_id', $projectId)->where('entity_type', $entity)
            ->where('metadata_json->operation_id', $operationId)->first();

        if ($replayed === null) {
            return null;
        }

        return hash_equals((string) ($replayed->metadata_json['operation_payload_hash'] ?? ''), $payloadHash)
            ? [$replayed, 'replayed']
            : [null, 'Mã thao tác này đã dùng cho một lần duyệt khác — tải lại trang.'];
    }

    /** @param array<string, mixed> $metadata */
    private function decide(string $projectId, string $entity, string $stageId, ?string $reviewerId, array $metadata): VideoReviewDecision
    {
        return VideoReviewDecision::create([
            'project_id' => $projectId,
            'session_id' => null,
            'entity_type' => $entity,
            'entity_id' => $stageId,
            'decision' => 'approved',
            'revision' => 1 + (int) VideoReviewDecision::query()->where('entity_type', $entity)->where('entity_id', $stageId)->max('revision'),
            'reviewer_id' => $reviewerId,
            'reason' => null,
            'metadata_json' => $metadata,
        ]);
    }

    /** @return array{version: int, text: ?array<string, mixed>, images: array<string, array<string, mixed>>, set: ?array<string, mixed>} */
    private function selectionOf(VideoProject $project): array
    {
        $stored = $project->metadata_json[self::SELECTION_KEY] ?? null;
        $stored = is_array($stored) ? $stored : [];

        return [
            'version' => (int) ($stored['version'] ?? 0),
            'text' => is_array($stored['text'] ?? null) ? $stored['text'] : null,
            'images' => is_array($stored['images'] ?? null) ? $stored['images'] : [],
            'set' => is_array($stored['set'] ?? null) ? $stored['set'] : null,
        ];
    }

    /** @param array<string, mixed> $selection */
    private function store(VideoProject $project, array $selection): void
    {
        $metadata = $project->metadata_json ?? [];
        $metadata[self::SELECTION_KEY] = $selection;
        $project->metadata_json = $metadata;
        $project->save();
    }
}
