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
use App\Video\Llm\JsonAnswer;
use App\Video\Locations\LocationReferencePrompt;
use App\Video\Locations\LocationWorkflow;
use App\Video\Script\ScriptContentHash;
use App\Video\Script\ScriptEvaluation;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class LocationSourceService
{
    public const TEXT_ENTITY = 'backgrounds_text';

    public const SELECTION_KEY = 'backgrounds_selection';

    public const TOKEN_VERSION = 'locations-source-token-v6';

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

    /** @return array{0: array<string, mixed>, 1: ?string} a Phase 5 stage re-evaluated from its raw answer and pinned contract, a stage of an older format as it was checked when it ran, or why it cannot be trusted */
    public function inspect(string $projectId, VideoPlanningStage $stage): array
    {
        try {
            $flow = $this->workflowFor($projectId);
        } catch (RuntimeException $e) {
            return [[], $e->getMessage()];
        }

        $format = is_array($stage->output_json) ? ($stage->output_json['format'] ?? null) : null;

        return is_string($format) && $format !== $flow['adapter']->format()
            ? $this->legacy($stage)
            : $this->evaluate($stage, $flow['adapter']);
    }

    /**
     * @param  array<string, mixed>  $plan  LocationReferencePlanner::plan() of a Phase 5 document
     * @param  array<string, mixed>  $config  video.superyacht.locations.reference
     * @return array{composer: string, config_hash: string, images: array<string, array{prompt: string, sha256: string}>, hash: string} the whole prompt of every background of the plan, as the page shows it, the approval stores it and the render sends it
     */
    public static function prompts(array $plan, array $config): array
    {
        $images = [];

        foreach ((array) $plan['images'] as $row) {
            $prompt = LocationReferencePrompt::compose($row, $config);
            $images[(string) $row['id']] = ['prompt' => $prompt, 'sha256' => CanonicalJson::hashString($prompt)];
        }

        $set = ['composer' => LocationReferencePrompt::VERSION, 'config_hash' => CanonicalJson::hash($config), 'images' => $images];

        return $set + ['hash' => CanonicalJson::hash($set)];
    }

    /**
     * @param  string  $expectedPromptsHash  the hash of the prompts the page showed, so that only what was read is put in use
     * @return array{0: ?VideoReviewDecision, 1: string}
     */
    public function approveText(string $projectId, string $stageId, string $expectedHash, string $expectedPromptsHash, int $expectedVersion, string $operationId, ?string $reviewerId): array
    {
        if (! Str::isUuid($operationId)) {
            return [null, 'Mã thao tác duyệt không hợp lệ — tải lại trang.'];
        }

        try {
            $flow = $this->workflowFor($projectId);
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }

        return DB::transaction(function () use ($projectId, $stageId, $expectedHash, $expectedPromptsHash, $expectedVersion, $operationId, $reviewerId, $flow): array {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->first();

            if ($project === null) {
                return [null, 'Không tìm thấy dự án.'];
            }

            $payloadHash = hash('sha256', json_encode([$projectId, $stageId, $expectedHash, $expectedPromptsHash, $expectedVersion], JSON_THROW_ON_ERROR));
            $replayed = $this->replayed($projectId, self::TEXT_ENTITY, $operationId, $payloadHash);

            if ($replayed !== null) {
                return $replayed;
            }

            $stage = $this->locationStage($projectId, $stageId);

            if ($stage === null) {
                return [null, 'Bộ bối cảnh này không thuộc dự án hoặc chưa chạy thành công.'];
            }

            [$evaluation, $problem] = $this->evaluate($stage, $flow['adapter']);

            if ($problem !== null) {
                return [null, $problem];
            }

            if (! hash_equals((string) $evaluation['content_hash'], $expectedHash)) {
                return [null, 'Trang đang hiển thị một nội dung khác bộ đã lưu — tải lại trang.'];
            }

            $selection = $this->selectionOf($project);

            if ($selection['version'] !== $expectedVersion) {
                return [null, 'Bộ bối cảnh đã đổi ở một tab khác — tải lại trang rồi thử lại.'];
            }

            $check = $evaluation['check'];

            if ($check['verdict'] !== 'PASS') {
                return [null, 'Bộ bối cảnh chưa đạt kiểm RULE — không render được.'];
            }

            if (! in_array($check['status'], ['ok', 'partial'], true)) {
                return [null, 'Model trả status '.json_encode($check['status']).' — bộ này chỉ để xem.'];
            }

            if (($problem = $flow['adapter']->sourceProblem($projectId, $this->source($stage))) !== null) {
                return [null, 'Nguồn của bộ bối cảnh không còn hợp lệ: '.$problem];
            }

            $plan = (array) $evaluation['derived'];
            $prompts = self::prompts($plan, (array) $flow['adapter']->settings()['reference']);

            if (! hash_equals($prompts['hash'], $expectedPromptsHash)) {
                return [null, 'Prompt ghép lại khác prompt trên trang (bộ ghép hoặc cấu hình vừa đổi) — tải lại trang để xem prompt mới.'];
            }

            $sameText = ($selection['text']['stage_id'] ?? null) === (string) $stage->id;

            if ($sameText && ($selection['text']['prompts_hash'] ?? null) === $prompts['hash']) {
                return [null, 'Bộ bối cảnh này đã được dùng với đúng các prompt này.'];
            }

            $decision = $this->decide($projectId, self::TEXT_ENTITY, (string) $stage->id, $reviewerId, [
                'operation_id' => $operationId,
                'operation_payload_hash' => $payloadHash,
                'content_hash' => $evaluation['content_hash'],
                'contract' => $evaluation['contract'],
                'check_version' => $check['version'],
                'planner_version' => $plan['version'] ?? null,
                'locations' => array_values((array) $plan['locations']),
                'images' => array_values((array) $plan['images']),
                'prompts' => $prompts,
                'unplated' => array_values((array) $plan['unplated']),
                'unresolved' => array_values((array) $plan['unresolved']),
                'conflicts' => array_values((array) $plan['conflicts']),
                'continuity' => array_values((array) $plan['continuity']),
                'script_continuity' => array_values((array) $plan['script_continuity']),
                'planning_revision' => $stage->planning_revision,
                'selection_version' => $selection['version'] + 1,
            ]);
            $this->store($project, [
                'version' => $selection['version'] + 1,
                'text' => ['stage_id' => (string) $stage->id, 'content_hash' => $evaluation['content_hash'], 'prompts_hash' => $prompts['hash'], 'approval_id' => (string) $decision->id, 'approved_at' => now()->toIso8601String()],
                'images' => $sameText ? $selection['images'] : [],
                'set' => null,
            ]);

            return [$decision, 'approved'];
        });
    }

    /**
     * @return array{stage: VideoPlanningStage, evaluation: array<string, mixed>, flow: array<string, mixed>, selection: array<string, mixed>, prompts: array<string, mixed>, stale: list<string>, current: array<string, mixed>}
     *
     * @throws RuntimeException when no background set is in use or it no longer serves
     */
    public function approvedText(string $projectId): array
    {
        $flow = $this->workflowFor($projectId);
        $selection = $this->selection($projectId);
        $text = $selection['text'] ?? throw new RuntimeException('Chưa có bộ bối cảnh nào đang dùng — bấm Render Plate ở một bộ đạt kiểm để dùng bộ đó.');
        $stage = $this->locationStage($projectId, (string) ($text['stage_id'] ?? '')) ?? throw new RuntimeException('Bộ bối cảnh đang dùng không còn hoặc không thuộc dự án.');
        $decision = VideoReviewDecision::query()->whereKey($text['approval_id'] ?? null)->where('project_id', $projectId)
            ->where('entity_type', self::TEXT_ENTITY)->where('entity_id', $stage->id)->where('decision', 'approved')->first();

        if ($decision === null) {
            throw new RuntimeException('Quyết định dùng bộ bối cảnh này không hợp lệ.');
        }

        [$evaluation, $problem] = $this->evaluate($stage, $flow['adapter']);

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        if (! hash_equals((string) $evaluation['content_hash'], (string) ($text['content_hash'] ?? ''))) {
            throw new RuntimeException('Nội dung bộ bối cảnh không khớp bộ đang dùng.');
        }

        $prompts = (array) ($decision->metadata_json['prompts'] ?? []);

        if (! is_array($prompts['images'] ?? null) || ! hash_equals((string) ($text['prompts_hash'] ?? ''), CanonicalJson::hash(array_diff_key($prompts, ['hash' => true])))) {
            throw new RuntimeException('Prompt đang dùng của bộ bối cảnh không còn nguyên vẹn — tải lại trang rồi bấm Render Plate.');
        }

        if (($problem = $flow['adapter']->sourceProblem($projectId, $this->source($stage))) !== null) {
            throw new RuntimeException('Nguồn của bộ bối cảnh đang dùng không còn hợp lệ: '.$problem);
        }

        $current = self::prompts((array) $evaluation['derived'], (array) $flow['adapter']->settings()['reference']);
        $stale = array_values(array_filter(
            array_keys($current['images']),
            static fn (string $id): bool => ($prompts['images'][$id]['sha256'] ?? null) !== $current['images'][$id]['sha256'],
        ));

        return ['stage' => $stage, 'evaluation' => $evaluation, 'flow' => $flow, 'selection' => $selection, 'prompts' => $prompts, 'stale' => $stale, 'current' => $current];
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
            return [false, "Bộ bối cảnh đang dùng không có bối cảnh {$requestId}."];
        }

        if ($request['blocked']) {
            return [false, "Bối cảnh {$requestId} còn xung đột kịch bản / thiết kế chưa giải quyết — không duyệt ảnh."];
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
                return [false, 'Ảnh này không thuộc bối cảnh hoặc bộ bối cảnh đang dùng.'];
            }

            if (($spec['prompt_sha256'] ?? null) !== ($text['prompts']['images'][$requestId]['sha256'] ?? null)) {
                return [false, "Ảnh này render từ prompt cũ của {$requestId} — bấm Render Plate để render với prompt hiện tại."];
            }

            if (! in_array($image->status, [DesignImageStatus::RENDERED->value, DesignImageStatus::APPROVED->value], true)) {
                return [false, 'Ảnh này chưa render xong.'];
            }

            if (($problem = self::fileProblem($artifact, (string) $artifact->sha256)) !== null) {
                return [false, $problem];
            }

            $images = $selection['images'];
            $images[$requestId] = ['image_id' => (string) $image->id, 'artifact_id' => (string) $artifact->id, 'sha256' => (string) $artifact->sha256, 'prompt_sha256' => (string) $spec['prompt_sha256'], 'chosen_at' => now()->toIso8601String()];
            $this->store($project, ['version' => $selection['version'] + 1, 'images' => $images, 'set' => null] + $selection);

            return [true, 'ok'];
        });
    }

    /**
     * @param  array<string, mixed>  $text  approvedText()
     * @return list<array{request: array<string, mixed>, prompt: string, chosen: ?array<string, mixed>, problem: ?string}> every background of the set in use with its approved prompt, what is chosen for it and why it is not ready yet
     */
    public function manifest(array $text): array
    {
        $plan = (array) $text['evaluation']['derived'];
        $chosen = (array) $text['selection']['images'];
        $rows = [];

        foreach ((array) $plan['images'] as $request) {
            $id = (string) $request['id'];
            $approved = $text['prompts']['images'][$id] ?? null;
            $pick = $chosen[$id] ?? null;
            $problem = null;

            if ($request['blocked']) {
                $problem = 'còn xung đột kịch bản / thiết kế chưa giải quyết';
            } elseif ($approved === null || in_array($id, $text['stale'], true)) {
                $problem = 'prompt đã đổi sau lần render trước — bấm Render Plate để render lại';
            } elseif ($pick === null) {
                $problem = 'chưa duyệt ảnh';
            } elseif (($pick['prompt_sha256'] ?? null) !== $approved['sha256']) {
                $problem = 'ảnh đã duyệt render từ prompt cũ — render lại';
            } else {
                $artifact = VideoArtifact::query()->whereKey($pick['artifact_id'] ?? null)->where('project_id', $text['stage']->project_id)->first();
                $problem = $artifact === null ? 'ảnh đã chọn không còn' : self::fileProblem($artifact, (string) ($pick['sha256'] ?? ''));
            }

            $rows[] = ['request' => $request, 'prompt' => (string) ($approved['prompt'] ?? ''), 'chosen' => $pick, 'problem' => $problem];
        }

        return $rows;
    }

    /**
     * @return array{stage_id: string, locations: list<array<string, mixed>>, document: array<string, mixed>, plan: array<string, mixed>, manifest: list<array<string, mixed>>, unplated: list<array<string, mixed>>, conflicts: list<array<string, mixed>>, continuity: list<array<string, mixed>>, script_continuity: list<array<string, mixed>>, review: array{vision: string, manual_review: string, semantic_review: string}, token: array<string, mixed>} the set the shot step takes, once every background of the set in use has an approved image rendered from its pinned prompt and nothing stays unresolved
     *
     * @throws RuntimeException while a background has no approved image, a use is unresolved or a conflict is open
     */
    public function approvedSource(string $projectId): array
    {
        $text = $this->approvedText($projectId);
        $selection = $text['selection'];
        $plan = (array) $text['evaluation']['derived'];
        $rows = $this->manifest($text);

        foreach ($rows as $row) {
            if ($row['problem'] !== null) {
                throw new RuntimeException("Bộ bối cảnh chưa đủ: {$row['request']['id']} {$row['problem']}.");
            }
        }

        foreach ((array) $plan['unresolved'] as $entry) {
            throw new RuntimeException("Bộ bối cảnh chưa đủ: {$entry['beat']} còn mục chưa xác định — {$entry['text']}.");
        }

        foreach ((array) $plan['conflicts'] as $conflict) {
            throw new RuntimeException('Bộ bối cảnh chưa đủ: còn xung đột kịch bản / thiết kế — '.$conflict['field'].'.');
        }

        $manifest = self::handoff($rows);
        $source = $this->source($text['stage']);

        return [
            'stage_id' => (string) $text['stage']->id,
            'locations' => array_values((array) $plan['locations']),
            'document' => $text['evaluation']['document'],
            'plan' => $plan,
            'manifest' => $manifest,
            'unplated' => array_values((array) $plan['unplated']),
            'conflicts' => array_values((array) $plan['conflicts']),
            'continuity' => array_values((array) $plan['continuity']),
            'script_continuity' => array_values((array) $plan['script_continuity']),
            'review' => ['vision' => 'not_run', 'manual_review' => 'approved_per_image', 'semantic_review' => (string) $text['flow']['adapter']->settings()['semantic_review']],
            'token' => [
                'version' => self::TOKEN_VERSION,
                'stage_id' => (string) $text['stage']->id,
                'content_hash' => (string) $text['evaluation']['content_hash'],
                'prompts_hash' => (string) $selection['text']['prompts_hash'],
                'text_approval_id' => (string) $selection['text']['approval_id'],
                'manifest_hash' => CanonicalJson::hash($manifest),
                'selection_version' => $selection['version'],
                'source_fingerprint' => CanonicalJson::hash($source),
            ],
        ];
    }

    /**
     * @param  array{evaluation: array<string, mixed>}  $text
     * @return ?array<string, mixed> the plan row of a background of the set in use
     */
    public function request(array $text, string $requestId): ?array
    {
        foreach ((array) ($text['evaluation']['derived']['images'] ?? []) as $request) {
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
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>> every chosen background with its source, the location it stands on, its state, the beats it serves, the changes and continuity the shot step must keep and the prompt it was rendered from
     */
    private static function handoff(array $rows): array
    {
        $manifest = [];

        foreach ($rows as $row) {
            if ($row['chosen'] === null) {
                continue;
            }

            $request = $row['request'];
            $manifest[] = [
                'background' => $request['id'],
                'name' => $request['name'],
                'kind' => $request['kind'],
                'source' => $request['source'],
                'location' => $request['location'],
                'state' => $request['state'],
                'condition' => $request['condition'],
                'details' => $request['details'],
                'uses' => $request['uses'],
                'parts' => $request['parts'],
                'sharing' => $request['sharing'],
                'light' => $request['light'],
                'shot_changes' => $request['shot_changes'],
                'continuity' => $request['continuity'],
                'script_continuity' => $request['script_continuity'],
                'must_show' => $request['must_show'],
                'avoid' => $request['avoid'],
                'assumptions' => $request['assumptions'],
                'prompt' => $row['prompt'],
                'prompt_sha256' => (string) $row['chosen']['prompt_sha256'],
                'composer' => LocationReferencePrompt::VERSION,
                'image_id' => (string) $row['chosen']['image_id'],
                'artifact_id' => (string) $row['chosen']['artifact_id'],
                'sha256' => (string) $row['chosen']['sha256'],
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
            return [[], 'Bản này không lưu đủ manifest, ngữ cảnh kiểm và nguồn của lượt chạy.'];
        }

        if (($output['format'] ?? null) !== $adapter->format()) {
            return [[], 'Bản này theo định dạng '.json_encode($output['format'] ?? null).', bước này chỉ hỗ trợ '.$adapter->format().'.'];
        }

        try {
            $context = $adapter->completeContext((string) $stage->project_id, $meta['context'], $meta['source']);
        } catch (RuntimeException $e) {
            return [[], 'Không bổ sung được dữ liệu thiết kế đã ghim của bản này: '.$e->getMessage()];
        }

        try {
            $evaluation = ScriptEvaluation::of((string) $stage->raw_response, $meta['manifest'], $context, $meta['source'], $adapter);
        } catch (RuntimeException $e) {
            return [[], 'Không kiểm lại được bản này theo hợp đồng của nó: '.$e->getMessage()];
        }

        if ($evaluation['content_hash'] === null || ! hash_equals($evaluation['content_hash'], (string) ($output['content_hash'] ?? ''))) {
            return [[], 'Nội dung bản đã lưu không khớp hash của nó — không dùng được.'];
        }

        return [$evaluation, null];
    }

    /** @return array{0: array<string, mixed>, 1: ?string} the stored check and plan of a Lập địa điểm stage of an older format, once its stored content matches its raw answer and pinned contract; it is never re-checked under the current contract, only shown */
    private function legacy(VideoPlanningStage $stage): array
    {
        $meta = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null);
        $output = (array) $stage->output_json;

        if (! is_array($meta['manifest'] ?? null) || ! is_array($meta['source'] ?? null)) {
            return [[], 'Bản mô tả này không lưu đủ manifest và nguồn của lượt chạy.'];
        }

        try {
            $contract = ScriptEvaluation::contract($meta['manifest']);
        } catch (RuntimeException $e) {
            return [[], 'Không đọc được hợp đồng của bản mô tả cũ: '.$e->getMessage()];
        }

        [$decoded, $parseError] = JsonAnswer::parse((string) $stage->raw_response);
        $document = $parseError === null ? json_decode((string) json_encode($decoded), true) : null;

        if (! is_array($document) || array_is_list($document) || ! hash_equals(ScriptContentHash::of($contract, $document, $meta['source']), (string) ($output['content_hash'] ?? ''))) {
            return [[], 'Nội dung bản mô tả đã lưu không khớp hash của nó — không dùng được.'];
        }

        return [[
            'document' => $document,
            'parse_error' => null,
            'check' => is_array($output['check'] ?? null) ? $output['check'] : null,
            'derived' => is_array($output['derived'] ?? null) ? $output['derived'] : null,
            'contract' => $contract,
            'content_hash' => (string) $output['content_hash'],
            'legacy' => (string) $output['format'],
        ], null];
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
