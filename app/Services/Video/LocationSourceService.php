<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Enums\PlanningStageName;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoReviewDecision;
use App\Repositories\Interfaces\VideoArtifactRepositoryInterface;
use App\Repositories\Interfaces\VideoDesignImageRepositoryInterface;
use App\Repositories\Interfaces\VideoPlanningStageRepositoryInterface;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Repositories\Interfaces\VideoReviewDecisionRepositoryInterface;
use App\Video\Llm\JsonAnswer;
use App\Video\Locations\LocationReferencePrompt;
use App\Video\Locations\LocationWorkflow;
use App\Video\Script\ScriptContentHash;
use App\Video\Script\ScriptEvaluation;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Video\Moto\MotoCheckRules;
use App\Video\Moto\MotoLocationImagePlan;
use App\Video\Moto\MotoLocations;
use RuntimeException;

final class LocationSourceService
{
    public const TEXT_ENTITY = 'backgrounds_text';

    public const SELECTION_KEY = 'backgrounds_selection';

    /** @var string inside the selection, the standard plate a person chose for each place of several plates (MotoLocationImagePlan::entry), by place. Code metadata only: the image in use for a standard plate stays the one images holds for that plate */
    public const STANDARDS_KEY = 'standards';

    /** @var string inside the selection, the trace of every choice the image plan took back: one record for each plate whose chosen image stopped belonging to the plan when the image of a standard plate, or the standard plate itself, changed — its place, plate, image, artifact, the state it was taken back in, what caused it, the selection version, when and by whom. Code metadata only; the images themselves are never touched */
    public const RELEASED_KEY = 'released';

    /** @var string inside the text entry of the selection, the mechanism the plates of the set in use are rendered under, written when the set is put in use and only for a set held to the shared reference. It lets a step after Phase 5 know, from the selection it has already read, that the chosen images must pass the shared check before they are taken (handedImages()); a set whose plates render on their own has no such key and is read exactly as before */
    public const MECHANISM_KEY = 'mechanism';

    public const TOKEN_VERSION = 'locations-source-token-v6';

    public function __construct(
        private readonly ScriptEvaluation $evaluation,
        private readonly VideoProjectRepositoryInterface $projects,
        private readonly VideoPlanningStageRepositoryInterface $stages,
        private readonly VideoReviewDecisionRepositoryInterface $decisions,
        private readonly VideoArtifactRepositoryInterface $artifacts,
        private readonly VideoDesignImageRepositoryInterface $images,
    ) {}

    /** @return array{workflow: string, action: string, adapter: LocationWorkflow, category_id: string, category_slug: string} */
    public function workflowFor(string $projectId): array
    {
        $project = $this->projects->findProjectWithCategory($projectId) ?? throw new RuntimeException('Không tìm thấy dự án.');
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

        if ($adapter instanceof \App\Video\Moto\ReleaseBound) {
            $adapter->bind($project);
        }

        return ['workflow' => $workflow, 'action' => (string) $entry['action'], 'adapter' => $adapter, 'category_id' => (string) $category->id, 'category_slug' => (string) $category->slug];
    }

    /** @return array{version: int, text: ?array<string, mixed>, images: array<string, array<string, mixed>>, set: ?array<string, mixed>, standards: array<string, array<string, mixed>>} */
    public function selection(string $projectId): array
    {
        return $this->selectionOf($this->projects->findProject($projectId) ?? new VideoProject);
    }

    /**
     * @param  array<string, mixed>  $evaluation  a Phase 5 stage as inspect() or approvedText() read it
     * @param  string  $stageId  that stage
     * @param  array<string, mixed>  $selection  selection()
     * @return ?array<string, array<string, mixed>> MotoLocationImagePlan::places() of the stage with the standard plates the selection holds for it — the places, their plates, the first plate that may render, the plate chosen in its place and so the role of every plate; null for a set whose plates each render on their own (MotoLocations::renderHold says nothing of its format): every format before moto-locations-v4 and every other workflow. A set held to the shared reference that was not planned has no place and so no plate that renders
     */
    public static function imagePlaces(array $evaluation, string $stageId, array $selection): ?array
    {
        $document = is_array($evaluation['document'] ?? null) ? $evaluation['document'] : [];
        $derived = $evaluation['derived'] ?? null;

        if (MotoLocations::renderHold((string) ($document['format'] ?? '')) === null) {
            return null;
        }

        if (isset($evaluation['legacy']) || ! is_array($derived)) {
            return [];
        }

        return MotoLocationImagePlan::places(
            (array) ($document['data'] ?? []),
            array_values(array_map('strval', array_column(array_filter((array) ($derived['images'] ?? []), static fn (array $row): bool => (bool) $row['blocked']), 'id'))),
            (array) ($selection[self::STANDARDS_KEY] ?? []),
            $stageId,
            (string) ($evaluation['content_hash'] ?? ''),
        );
    }

    public function locationStage(string $projectId, string $stageId): ?VideoPlanningStage
    {
        return $this->stages->findSucceededSourceStage($projectId, $stageId, PlanningStageName::LOCATIONS);
    }

    /** @return array{0: array<string, mixed>, 1: ?string} a Phase 5 stage re-evaluated from its raw answer and pinned contract — under the format its run pinned, whenever the workflow still reads it — a stage of a format it no longer reads as it was checked when it ran, or why it cannot be trusted */
    public function inspect(string $projectId, VideoPlanningStage $stage): array
    {
        try {
            $flow = $this->workflowFor($projectId);
        } catch (RuntimeException $e) {
            return [[], $e->getMessage()];
        }

        $format = is_array($stage->output_json) ? ($stage->output_json['format'] ?? null) : null;

        return is_string($format) && ! in_array($format, $flow['adapter']->formats(), true)
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
        $variant = $config['variants'][(string) ($plan['version'] ?? '')] ?? null;
        $config = is_array($variant) ? $variant : array_diff_key($config, ['variants' => true]);
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

            if (! MotoCheckRules::usable($check)) {
                return [null, $check['verdict'] !== 'PASS' ? 'Bộ bối cảnh chưa đạt kiểm RULE — không render được.' : 'Bộ bối cảnh còn điều kiện chưa đạt để dùng cho bước sau: '.implode('; ', (array) $check['requirements']).' — không render được.'];
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
                'text' => ['stage_id' => (string) $stage->id, 'content_hash' => $evaluation['content_hash'], 'prompts_hash' => $prompts['hash'], 'approval_id' => (string) $decision->id, 'approved_at' => now()->toIso8601String()]
                    + (MotoLocations::renderHold((string) ($evaluation['document']['format'] ?? '')) === null ? [] : [self::MECHANISM_KEY => MotoLocationImagePlan::MECHANISM]),
                'images' => $sameText ? $selection['images'] : [],
                'set' => null,
                self::STANDARDS_KEY => $sameText ? $selection[self::STANDARDS_KEY] : [],
                self::RELEASED_KEY => $sameText ? $selection[self::RELEASED_KEY] : [],
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
        $decision = $this->decisions->findApprovalFor($projectId, $text['approval_id'] ?? null, self::TEXT_ENTITY, $stage->id);

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

        // The next step reads this location set only when every condition it needs still holds — before any paid call.
        MotoCheckRules::requireUsable((array) $evaluation['check'], 'Bộ bối cảnh đang dùng');

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

    /**
     * @param  ?string  $actorId  who chooses; kept with the trace of every choice this one takes back
     * @return array{0: bool, 1: string} whether the image is now the one in use for its background. Each background is chosen on its own: choices of other backgrounds made since the page was drawn do not stop this one, and every choice is written under the project lock from the selection as it stands then. In a set rendered under the shared reference an image is chosen only while it still belongs to the image plan (referenceOf()): made in the role its plate has now and, for a variant, from the source in use now. Choosing the image of a standard plate takes back the choices of the other plates of that place that were made from another source — their images stay, with their history and cost, and each choice taken back is recorded under released; choosing the same artifact again takes nothing back
     */
    public function choose(string $projectId, string $requestId, string $artifactId, ?string $actorId = null): array
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

        return DB::transaction(function () use ($projectId, $requestId, $artifactId, $actorId, $text): array {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $selection = $this->selectionOf($project);

            if (($selection['text'] ?? null) != ($text['selection']['text'] ?? null)) {
                return [false, 'Bộ bối cảnh đang dùng vừa đổi — tải lại trang rồi chọn lại.'];
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

            $reference = $this->referenceOf(['selection' => $selection] + $text, $requestId, $spec);

            if ($reference['problem'] !== null) {
                return [false, $reference['problem']];
            }

            $images = $selection['images'];
            $images[$requestId] = ['image_id' => (string) $image->id, 'artifact_id' => (string) $artifact->id, 'sha256' => (string) $artifact->sha256, 'prompt_sha256' => (string) $spec['prompt_sha256'], 'chosen_at' => now()->toIso8601String()];
            $next = ['version' => $selection['version'] + 1, 'images' => $images, 'set' => null] + $selection;

            if ($reference['role'] === MotoLocationImagePlan::STANDARD) {
                $released = $this->unfit(['selection' => $next] + $text, (string) $reference['location']);
                $next[self::RELEASED_KEY] = self::releasedWith($selection, $released, (string) $reference['location'], 'standard_image', $next['version'], $actorId);
                $next['images'] = array_diff_key($images, $released);
            }

            $this->store($project, $next);

            return [true, 'ok'];
        });
    }

    /**
     * @param  string  $location  a place of the set
     * @param  string  $plate  the plate of that place to render first and to render the others from
     * @param  int  $expectedVersion  the version of the selection the page showed
     * @param  ?string  $actorId  who chooses
     * @param  ?array{stage_id: string, content_hash: string, prompts_hash: string}  $shown  the set the page showed and the prompt set it showed for it. A set that is not in use yet, or whose prompts changed, is first put in use with exactly these prompts, as a render does (approveText()), and the choice is then stored — both or neither: when either is refused nothing is written. null chooses on the set in use only
     * @param  ?string  $operationId  the operation of the page, for putting the set in use
     * @return array{0: bool, 1: string, 2: list<string>} whether the plate is the standard plate of its place now, the reason — ok, unchanged when it already was, or why not — and the plates of the place whose chosen image was taken back. Only a place of several plates is chosen for; the plate must be one of the place and held by no conflict. The choice stores the image plan alone: it chooses no artifact and never stands in for the approval of the content. Changing the standard of a place is the same act: every image of the place stays, and a choice is kept only where the image was made in the role its plate has under the new plan and from the source in use under it — so an image made from the old standard is never the source of the new one; each choice taken back is recorded under released
     */
    public function chooseStandard(string $projectId, string $location, string $plate, int $expectedVersion, ?string $actorId, ?array $shown = null, ?string $operationId = null): array
    {
        DB::beginTransaction();

        try {
            $answer = $this->standardChoice($projectId, $location, $plate, $expectedVersion, $actorId, $shown, $operationId);
            $answer[0] ? DB::commit() : DB::rollBack();

            return $answer;
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /**
     * @param  ?array{stage_id: string, content_hash: string, prompts_hash: string}  $shown
     * @return array{0: bool, 1: string, 2: list<string>} chooseStandard(), inside the transaction that keeps putting the set in use and storing the choice one act
     */
    private function standardChoice(string $projectId, string $location, string $plate, int $expectedVersion, ?string $actorId, ?array $shown, ?string $operationId): array
    {
        if ($shown !== null) {
            $inUse = $this->selection($projectId)['text'];

            if (($inUse['stage_id'] ?? null) !== $shown['stage_id'] || ($inUse['prompts_hash'] ?? null) !== $shown['prompts_hash']) {
                [$decision, $reason] = $this->approveText($projectId, $shown['stage_id'], $shown['content_hash'], $shown['prompts_hash'], $expectedVersion, (string) $operationId, $actorId);

                if ($decision === null) {
                    return [false, $reason, []];
                }

                $expectedVersion += $reason === 'approved' ? 1 : 0;
            }
        }

        try {
            $text = $this->approvedText($projectId);
        } catch (RuntimeException $e) {
            return [false, $e->getMessage(), []];
        }

        if ($shown !== null && ((string) $text['stage']->id !== $shown['stage_id'] || ! hash_equals((string) $text['evaluation']['content_hash'], $shown['content_hash']))) {
            return [false, 'Trang đang hiển thị một bộ khác bộ bối cảnh đang dùng — tải lại trang.', []];
        }

        return DB::transaction(function () use ($projectId, $location, $plate, $expectedVersion, $actorId, $text): array {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $selection = $this->selectionOf($project);

            if (($selection['text'] ?? null) != ($text['selection']['text'] ?? null)) {
                return [false, 'Bộ bối cảnh đang dùng vừa đổi — tải lại trang rồi chọn lại.', []];
            }

            if ($selection['version'] !== $expectedVersion) {
                return [false, 'Bộ bối cảnh đã đổi ở một tab khác — tải lại trang rồi thử lại.', []];
            }

            $stageId = (string) $text['stage']->id;
            $places = self::imagePlaces($text['evaluation'], $stageId, $selection);

            if ($places === null) {
                return [false, 'Bộ bối cảnh đang dùng dựng riêng từng ảnh nền — không có ảnh chuẩn của địa điểm để chọn.', []];
            }

            $place = $places[$location] ?? null;

            if ($place === null) {
                return [false, "Bộ bối cảnh đang dùng không có địa điểm {$location}.", []];
            }

            if (! in_array($plate, $place['plates'], true)) {
                return [false, "Ảnh nền {$plate} không thuộc địa điểm {$location}.", []];
            }

            if ($place['single']) {
                return [false, "Địa điểm {$location} chỉ có một ảnh nền — {$place['standard']} là ảnh chuẩn theo mặc định, không cần chọn.", []];
            }

            if (! collect($place['candidates'])->firstWhere('plate', $plate)['eligible']) {
                return [false, "Ảnh nền {$plate} còn xung đột kịch bản / thiết kế chưa giải quyết — không chọn làm ảnh chuẩn.", []];
            }

            if ($place['standard'] === $plate) {
                return [true, 'unchanged', []];
            }

            $version = $selection['version'] + 1;
            $standards = [$location => MotoLocationImagePlan::entry($stageId, (string) $text['evaluation']['content_hash'], $location, $plate, $version, $actorId, now()->toIso8601String())] + $selection[self::STANDARDS_KEY];
            $next = ['version' => $version, self::STANDARDS_KEY => $standards, 'set' => null] + $selection;
            $released = $this->unfit(['selection' => $next] + $text, $location);
            $next[self::RELEASED_KEY] = self::releasedWith($selection, $released, $location, 'standard_plate', $version, $actorId);
            $next['images'] = array_diff_key($selection['images'], $released);
            $this->store($project, $next);

            return [true, 'ok', array_keys($released)];
        });
    }

    /**
     * @param  array<string, mixed>  $selection  the selection as it stood before the choices were taken back
     * @param  array<string, string>  $released  unfit(): the plates whose choice is taken back, each with the state it was found in
     * @param  string  $cause  standard_image when another image was chosen for the standard plate of the place, standard_plate when another plate became its standard
     * @param  int  $version  the version of the selection that no longer holds those choices
     * @return list<array{location: string, plate: string, image_id: ?string, artifact_id: ?string, state: string, cause: string, selection_version: int, at: string, by: ?string}> the trace of every choice taken back so far with these added, oldest first
     */
    private static function releasedWith(array $selection, array $released, string $location, string $cause, int $version, ?string $actorId): array
    {
        $trace = array_values((array) $selection[self::RELEASED_KEY]);

        foreach ($released as $plate => $state) {
            $pick = (array) ($selection['images'][$plate] ?? []);
            $trace[] = [
                'location' => $location,
                'plate' => (string) $plate,
                'image_id' => $pick['image_id'] ?? null,
                'artifact_id' => $pick['artifact_id'] ?? null,
                'state' => $state,
                'cause' => $cause,
                'selection_version' => $version,
                'at' => now()->toIso8601String(),
                'by' => $actorId,
            ];
        }

        return $trace;
    }

    /**
     * @param  array<string, mixed>  $text  approvedText()
     * @param  string  $plate  a plate of the set in use
     * @param  ?array<string, mixed>  $spec  the stored spec of an image of that plate; null when a new request is asked for
     * @param  bool  $bytes  the file of the standard image is read and hashed; false only where a page is drawn, which asks no more than whether the file is there
     * @return array{mechanism: ?string, location: ?string, plate: string, role: ?string, standard_plate: ?string, basis: ?string, choice_version: ?int, source: ?array<string, mixed>, state: string, problem: ?string, brief: ?string} the one check of the shared reference, asked before a request is made, before a cell is sent again, before a queued job calls the provider, before an image is chosen and before a set is handed on (MotoLocationImagePlan::verdict). The role comes from the plan, never from the caller. For a variant the source is the image chosen now for the standard plate of its place: of this project, this stage, that plate and place, made as a standard from the prompt in use, with its file present and its SHA-256 unchanged. A set that is not rendered under the mechanism answers independent with no problem
     */
    public function referenceOf(array $text, string $plate, ?array $spec = null, bool $bytes = true): array
    {
        $places = self::imagePlaces($text['evaluation'], (string) $text['stage']->id, $text['selection']);

        return $places === null ? MotoLocationImagePlan::independent($plate) : $this->judge($text, $places, $plate, $spec, self::fileCheck($bytes));
    }

    /**
     * @param  array<string, mixed>  $text  approvedText() of a set held to the shared reference
     * @param  array<string, array<string, mixed>>  $places  imagePlaces() of that set
     * @param  ?array<string, mixed>  $spec  the stored spec of an image of the plate; null when a new request is asked for
     * @param  \Closure(VideoArtifact, string): ?string  $file  fileCheck()
     * @param  array<string, array<string, mixed>>  $standards  the standard source of every place asked about so far, by place: read once for all the plates of a place, and kept for the caller
     * @param  ?Collection<string, VideoDesignImage>  $images  images already read by their ids
     * @param  ?Collection<string, VideoArtifact>  $artifacts  artifacts already read by their ids
     * @return array<string, mixed> MotoLocationImagePlan::verdict() of the plate: the one judgement behind every gate, whether it is asked for one plate (referenceOf()) or for a whole set (manifest(), imagePlan()). A plate no place of the plan lists keeps the hold of its set
     */
    private function judge(array $text, array $places, string $plate, ?array $spec, \Closure $file, array &$standards = [], ?Collection $images = null, ?Collection $artifacts = null): array
    {
        $place = MotoLocationImagePlan::placeOf($places, $plate);

        if ($place === null) {
            return MotoLocationImagePlan::unplaced($plate, (string) MotoLocations::renderHold((string) ($text['evaluation']['document']['format'] ?? '')));
        }

        if (MotoLocationImagePlan::role($place, $plate) === MotoLocationImagePlan::VARIANT) {
            $standards[$place['location']] ??= $this->standardSource($text, $place, $file, $images, $artifacts);
        }

        return MotoLocationImagePlan::verdict($place, $plate, $standards[$place['location']] ?? null, $spec);
    }

    /**
     * @param  array<string, mixed>  $text  approvedText(); or, for a set on screen that is not in use, the same keys it reads — stage, evaluation, selection (with no chosen image), prompts.images and stale
     * @return array{mechanism: ?string, selection_version: int, places: list<array<string, mixed>>, images: array<string, array<string, mixed>>} the image plan of a set as a page shows it, read without opening an image file and with two queries however many plates there are. Each place: its plates, the first of them that may render, the plate a person chose in its place with who chose and when, the standard plate and why it is the standard, the artifact in use as the reference, and its state — no_standard_plate, no_standard_image, source_unusable or ready. Each plate (rows): its role, the capability and the sources its request needs, whether it may be rendered now and why not, the image chosen for it and whether that image still belongs to the plan, and — while it has no chosen image — the last choice the plan took back from it (released). images: for every image of the set, its role, the source it was really made from and whether it still belongs to the plan — source_changed for an image made from a standard that is no longer in use. mechanism null: every plate renders on its own
     */
    public function imagePlan(array $text): array
    {
        $stageId = (string) $text['stage']->id;
        $projectId = (string) $text['stage']->project_id;
        $places = self::imagePlaces($text['evaluation'], $stageId, $text['selection']);
        $plan = ['mechanism' => $places === null ? null : MotoLocationImagePlan::MECHANISM, 'selection_version' => (int) $text['selection']['version'], 'places' => [], 'images' => []];

        if ($places === null) {
            return $plan;
        }

        $chosen = (array) $text['selection']['images'];
        $cells = VideoDesignImage::query()->where('project_id', $projectId)->where('image_type', DesignImageStore::LOCATION_REFERENCE_TYPE)
            ->where('prompt_spec_json->locations_stage_id', $stageId)->get(['id', 'project_id', 'image_type', 'status', 'environment_key', 'prompt_spec_json'])->keyBy('id');
        $artifacts = $this->artifactsOf($projectId, array_column($chosen, 'artifact_id'));
        $requests = array_column((array) $text['evaluation']['derived']['images'], null, 'id');
        $takenBack = array_column(array_filter((array) ($text['selection'][self::RELEASED_KEY] ?? []), 'is_array'), null, 'plate');
        $found = self::fileCheck(false);
        $standards = [];

        foreach ($places as $location => $place) {
            $standard = $place['standard'] === null ? null : $this->standardSource($text, $place, $found, $cells, $artifacts);
            $standards[$location] = $standard;
            $rows = [];

            foreach ($place['plates'] as $plate) {
                $verdict = $this->judge($text, $places, $plate, null, $found, $standards, $cells, $artifacts);
                $pick = is_array($chosen[$plate] ?? null) ? $chosen[$plate] : null;
                $held = $pick === null ? null : $this->judge($text, $places, $plate, (array) ($cells->get((string) ($pick['image_id'] ?? ''))?->prompt_spec_json ?? []), $found, $standards, $cells, $artifacts);
                $own = match (true) {
                    (bool) ($requests[$plate]['blocked'] ?? true) => "Bối cảnh {$plate} còn xung đột kịch bản / thiết kế chưa giải quyết — không render.",
                    ! isset($text['prompts']['images'][$plate]) || in_array($plate, $text['stale'], true) => "Prompt của {$plate} đã đổi so với prompt đang dùng — tải lại trang rồi bấm Render Plate.",
                    default => null,
                };
                $rows[$plate] = [
                    'plate' => $plate,
                    'role' => $verdict['role'],
                    'capability' => $verdict['role'] === null ? null : MotoLocationImagePlan::CAPABILITY[$verdict['role']],
                    'sources' => $verdict['role'] === null ? null : MotoLocationImagePlan::SOURCES[$verdict['role']],
                    'source' => $verdict['source'],
                    'state' => $verdict['state'],
                    'can_render' => $own === null && $verdict['problem'] === null,
                    'reason' => $own ?? $verdict['problem'],
                    'plan_problem' => $verdict['problem'],
                    'chosen' => $pick,
                    'chosen_state' => $held['state'] ?? null,
                    'chosen_problem' => $held['problem'] ?? null,
                    'released' => $pick === null ? ($takenBack[$plate] ?? null) : null,
                ];
            }

            $plan['places'][] = [
                'location' => $location,
                'name' => $place['name'],
                'plates' => $place['plates'],
                'single' => $place['single'],
                'first' => $place['first'],
                'candidates' => $place['candidates'],
                'choice' => $place['choice'] === null ? null : array_intersect_key($place['choice'], array_flip(['plate', 'selection_version', 'chosen_by', 'chosen_at'])),
                'standard_plate' => $place['standard'],
                'basis' => $place['basis'],
                'standard' => $standard === null || $standard['source'] === null ? null : $standard['source'] + ['chosen_at' => $chosen[$place['standard']]['chosen_at'] ?? null],
                'state' => match (true) {
                    $standard === null => MotoLocationImagePlan::NO_STANDARD_PLATE,
                    $standard['problem'] !== null => $standard['state'],
                    default => MotoLocationImagePlan::READY,
                },
                'problem' => $standard === null ? MotoLocationImagePlan::verdict($place, $place['plates'][0], null)['problem'] : $standard['problem'],
                'rows' => $rows,
            ];
        }

        foreach ($cells as $cell) {
            $spec = (array) $cell->prompt_spec_json;
            $plate = (string) ($spec['request_id'] ?? '');
            $verdict = $this->judge($text, $places, $plate, $spec, $found, $standards, $cells, $artifacts);
            $plan['images'][(string) $cell->id] = [
                'plate' => $plate,
                'role' => $spec['reference']['role'] ?? null,
                'standard_plate' => $spec['reference']['standard_plate'] ?? null,
                'source' => is_array($spec['sources'][0] ?? null) ? array_intersect_key($spec['sources'][0], array_flip(['plate', 'candidate_id', 'artifact_id', 'sha256'])) : null,
                'state' => $verdict['state'],
                'current' => $verdict['problem'] === null,
                'problem' => $verdict['problem'],
            ];
        }

        return $plan;
    }

    /**
     * @param  ?array<string, mixed>  $selection  selection() of the project when the caller has already read it; null reads it
     * @return array<string, array<string, mixed>> the chosen images of the set in use that a step after Phase 5 may take, by background: the one view the shot step, the keyframe step and the probe read the selection through. A set whose plates render on their own is handed as it is chosen — the images of the selection, with no query and no file of its own, exactly as those steps read it before. A set put in use under the shared reference (MECHANISM_KEY) is handed only what still belongs to its image plan, by the same judgement every Phase 5 gate asks (judge()): the image of a standard plate while it was made as that standard, the image of a variant only while it was made from the artifact chosen for its standard now and that artifact can still serve — so a variant whose standard file is gone, holds other bytes than the ones chosen, or whose standard prompt is out of date is not taken although its choice stays. The set is read once (approvedText()), the chosen images and artifacts by id list; the file of a standard image is read and hashed only where a chosen variant depends on it, once for the whole call however many variants the place has, and no other file is opened. When the set in use cannot be read, nothing of it is handed
     */
    public function handedImages(string $projectId, ?array $selection = null): array
    {
        $selection ??= $this->selection($projectId);
        $images = (array) ($selection['images'] ?? []);

        if (($selection['text'][self::MECHANISM_KEY] ?? null) !== MotoLocationImagePlan::MECHANISM || $images === []) {
            return $images;
        }

        try {
            $text = $this->approvedText($projectId);
        } catch (RuntimeException) {
            return [];
        }

        $images = (array) $text['selection']['images'];
        $places = self::imagePlaces($text['evaluation'], (string) $text['stage']->id, $text['selection']);

        if ($places === null) {
            return $images;
        }

        $picks = array_filter($images, 'is_array');
        $rows = $this->imagesOf($projectId, array_column($picks, 'image_id'));
        $artifacts = $this->artifactsOf($projectId, array_column($picks, 'artifact_id'));
        $read = self::fileCheck(true);
        $standards = [];

        return array_filter($picks, function (array $pick, string|int $plate) use ($text, $places, $rows, $artifacts, $read, &$standards): bool {
            return $this->judge($text, $places, (string) $plate, (array) ($rows->get((string) ($pick['image_id'] ?? ''))?->prompt_spec_json ?? []), $read, $standards, $rows, $artifacts)['problem'] === null;
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  array<string, mixed>  $text  approvedText()
     * @param  bool  $bytes  every chosen file is read and its SHA-256 compared, as a hand-off needs; false, for drawing a page, only asks whether each file is there — a page then tells a missing file and never opens an image, so bytes that changed under an unchanged row are told where something is really used: a render, a choice, approvedSource() and handedImages()
     * @return list<array{request: array<string, mixed>, prompt: string, chosen: ?array<string, mixed>, problem: ?string, reference?: array<string, mixed>}> every background of the set in use with its approved prompt, what is chosen for it and why it is not ready yet. The chosen artifacts — and, for a set rendered under the shared reference, the images they belong to — are read once by their ids, never one background at a time. In such a set a chosen image with a valid file is still not ready when it no longer belongs to the image plan (referenceOf()): each row then also tells, under reference, the role of its plate, the standard plate of its place, the source the chosen image was really made from and whether it fits the plan as it stands
     */
    public function manifest(array $text, bool $bytes = true): array
    {
        $plan = (array) $text['evaluation']['derived'];
        $chosen = (array) $text['selection']['images'];
        $projectId = (string) $text['stage']->project_id;
        $places = self::imagePlaces($text['evaluation'], (string) $text['stage']->id, $text['selection']);
        $artifacts = $this->artifactsOf($projectId, array_column($chosen, 'artifact_id'));
        $images = $places === null ? new Collection : $this->imagesOf($projectId, array_column($chosen, 'image_id'));
        $file = self::fileCheck($bytes);
        $standards = [];
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
                $artifact = $artifacts->get((string) ($pick['artifact_id'] ?? ''));
                $problem = $artifact === null ? 'ảnh đã chọn không còn' : $file($artifact, (string) ($pick['sha256'] ?? ''));
            }

            $row = ['request' => $request, 'prompt' => (string) ($approved['prompt'] ?? ''), 'chosen' => $pick, 'problem' => $problem];

            if ($places !== null) {
                $spec = $pick === null ? null : (array) ($images->get((string) ($pick['image_id'] ?? ''))?->prompt_spec_json ?? []);
                $verdict = $this->judge($text, $places, $id, $spec, $file, $standards, $images, $artifacts);

                if ($pick !== null && $verdict['problem'] !== null) {
                    $row['problem'] = $problem ?? ($verdict['state'] === MotoLocationImagePlan::SOURCE_UNUSABLE ? $verdict['brief'].' — '.rtrim((string) $verdict['problem'], '.') : $verdict['brief']);
                }

                $row['reference'] = [
                    'mechanism' => MotoLocationImagePlan::MECHANISM,
                    'role' => $verdict['role'],
                    'location' => $verdict['location'],
                    'standard_plate' => $verdict['standard_plate'],
                    'source' => is_array($spec['sources'][0] ?? null) ? array_intersect_key($spec['sources'][0], array_flip(['plate', 'candidate_id', 'artifact_id', 'sha256'])) : null,
                    'state' => $verdict['state'],
                    'current' => $pick !== null && $verdict['problem'] === null,
                ];
            }

            $rows[] = $row;
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

        return $this->approvedSet($text, $this->manifest($text));
    }

    /**
     * @param  array<string, mixed>  $text  approvedText()
     * @param  list<array<string, mixed>>  $rows  manifest() of that text, computed once by the caller: with every file read for a hand-off (approvedSource()), or only looked for where a page shows whether the set is complete
     * @return array<string, mixed> approvedSource() of the set, built from those rows alone — no query and no file of its own
     *
     * @throws RuntimeException while a background has no approved image, a use is unresolved or a conflict is open
     */
    public function approvedSet(array $text, array $rows): array
    {
        $selection = $text['selection'];
        $plan = (array) $text['evaluation']['derived'];

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
     * @param  array<string, mixed>  $text  approvedText()
     * @param  array<string, mixed>  $place  one place of imagePlaces() that has a standard plate
     * @param  \Closure(VideoArtifact, string): ?string  $file  how the file of the artifact is checked (fileCheck()): read and hashed, or only found
     * @param  ?Collection<string, VideoDesignImage>  $images  images already read by their ids; null reads the one needed
     * @param  ?Collection<string, VideoArtifact>  $artifacts  artifacts already read by their ids; null reads the one needed
     * @return array{source: ?array{position: int, role: string, plate: string, candidate_id: string, artifact_id: string, sha256: string}, chosen: ?array{artifact_id: string, sha256: string}, state: string, problem: ?string} the image in use as the reference of the place: the artifact the image selection holds for its standard plate, and nothing else. It serves as a source only while the standard plate is held by no conflict and its prompt is the one in use, the artifact and its image belong to this project, this stage, that plate and place, the image was made as a standard under this mechanism from the prompt in use and has finished rendering, the stored hashes agree, and the file is there — with its SHA-256 unchanged when the bytes are read. Otherwise the reason, and no source. chosen names the artifact the selection holds for the standard plate whether or not it can serve, so that an image made from another artifact is told apart from one whose own source cannot be used; null while nothing is chosen
     */
    private function standardSource(array $text, array $place, \Closure $file, ?Collection $images = null, ?Collection $artifacts = null): array
    {
        $plate = (string) $place['standard'];
        $location = (string) $place['location'];
        $projectId = (string) $text['stage']->project_id;
        $pick = $text['selection']['images'][$plate] ?? null;
        $chosen = is_array($pick) ? ['artifact_id' => (string) ($pick['artifact_id'] ?? ''), 'sha256' => (string) ($pick['sha256'] ?? '')] : null;
        $stop = static fn (string $state, string $problem): array => ['source' => null, 'chosen' => $chosen, 'state' => $state, 'problem' => $problem];
        $alone = (bool) $place['single'];

        if (! is_array($pick)) {
            return $stop(MotoLocationImagePlan::NO_STANDARD_IMAGE, "Ảnh chuẩn {$plate} của {$location} chưa có ảnh được duyệt — render {$plate} rồi duyệt một ảnh".($alone ? '.' : ' trước; các ảnh nền khác của địa điểm dựng từ ảnh đó.'));
        }

        $request = $this->request($text, $plate);
        $approved = $text['prompts']['images'][$plate] ?? null;

        if ($request === null || $request['blocked']) {
            return $stop(MotoLocationImagePlan::SOURCE_UNUSABLE, "Ảnh chuẩn {$plate} của {$location} còn xung đột kịch bản / thiết kế chưa giải quyết — chưa dùng làm nguồn được.");
        }

        if ($approved === null || in_array($plate, $text['stale'], true) || ($pick['prompt_sha256'] ?? null) !== $approved['sha256']) {
            return $stop(MotoLocationImagePlan::SOURCE_UNUSABLE, "Ảnh đang dùng của ảnh chuẩn {$plate} dựng từ prompt cũ — render và duyệt lại {$plate}".($alone ? '.' : " trước khi dựng các ảnh nền khác của {$location}."));
        }

        $artifactId = (string) ($pick['artifact_id'] ?? '');
        $imageId = (string) ($pick['image_id'] ?? '');
        $artifact = ($artifacts ?? $this->artifactsOf($projectId, [$artifactId]))->get($artifactId);
        $image = ($images ?? $this->imagesOf($projectId, [$imageId]))->get($imageId);

        if ($artifact === null || $image === null || (string) $artifact->project_id !== $projectId || (string) $image->project_id !== $projectId
            || $image->image_type !== DesignImageStore::LOCATION_REFERENCE_TYPE || (string) $artifact->design_image_id !== $imageId) {
            return $stop(MotoLocationImagePlan::SOURCE_UNUSABLE, "Ảnh đang dùng của ảnh chuẩn {$plate} không còn hoặc không thuộc dự án này — duyệt lại ảnh cho {$plate}.");
        }

        $spec = (array) $image->prompt_spec_json;

        if (($spec['locations_stage_id'] ?? null) !== (string) $text['stage']->id || ($spec['request_id'] ?? null) !== $plate) {
            return $stop(MotoLocationImagePlan::SOURCE_UNUSABLE, "Ảnh đang dùng của ảnh chuẩn {$plate} không thuộc bộ bối cảnh đang dùng hoặc không phải ảnh của {$plate} — duyệt lại ảnh cho {$plate}.");
        }

        $own = MotoLocationImagePlan::verdict($place, $plate, null, $spec);

        if ($own['problem'] !== null) {
            return $stop(MotoLocationImagePlan::SOURCE_UNUSABLE, "Ảnh đang dùng của {$plate} không dùng làm ảnh chuẩn của {$location} được: {$own['problem']}");
        }

        if (($spec['prompt_sha256'] ?? null) !== $approved['sha256'] || ! in_array($image->status, [DesignImageStatus::RENDERED->value, DesignImageStatus::APPROVED->value], true)) {
            return $stop(MotoLocationImagePlan::SOURCE_UNUSABLE, "Ảnh đang dùng của ảnh chuẩn {$plate} chưa render xong hoặc dựng từ prompt cũ — render và duyệt lại {$plate}.");
        }

        $sha256 = (string) $artifact->sha256;

        if ($sha256 === '' || $sha256 !== (string) ($pick['sha256'] ?? '')) {
            return $stop(MotoLocationImagePlan::SOURCE_UNUSABLE, "Hash của ảnh chuẩn {$plate} không khớp ảnh đã duyệt".($alone ? '.' : ' — các ảnh nền dựng từ nó bị chặn.'));
        }

        $missing = $file($artifact, $sha256);

        if ($missing !== null) {
            return $stop(MotoLocationImagePlan::SOURCE_UNUSABLE, "Ảnh chuẩn {$plate} của {$location}: {$missing}".($alone ? '.' : ' — các ảnh nền dựng từ nó bị chặn.'));
        }

        return [
            'source' => ['position' => 0, 'role' => MotoLocationImagePlan::STANDARD, 'plate' => $plate, 'candidate_id' => $imageId, 'artifact_id' => $artifactId, 'sha256' => $sha256],
            'chosen' => $chosen,
            'state' => MotoLocationImagePlan::READY,
            'problem' => null,
        ];
    }

    /**
     * @param  bool  $bytes  the file is read and its SHA-256 compared; false asks only whether the file is there, as a page does
     * @return \Closure(VideoArtifact, string): ?string why the file of a chosen artifact cannot be used, null when it can. A file read once is not read again by the same check: a manifest that asks about the standard image of a place for each of its variants opens that file once
     */
    private static function fileCheck(bool $bytes): \Closure
    {
        $read = [];

        return static function (VideoArtifact $artifact, string $sha256) use ($bytes, &$read): ?string {
            $key = $artifact->id.':'.$sha256;

            if (! array_key_exists($key, $read)) {
                $read[$key] = match (true) {
                    $bytes => self::fileProblem($artifact, $sha256),
                    Storage::disk((string) $artifact->storage_disk)->exists((string) $artifact->storage_path) => null,
                    default => 'file ảnh không còn',
                };
            }

            return $read[$key];
        };
    }

    /**
     * @param  array<string, mixed>  $text  approvedText() with the selection as it is about to be stored
     * @param  string  $location  the place whose standard plate, or whose standard image, has just changed
     * @return array<string, string> the plates of the place whose chosen image no longer belongs to the image plan, each with the state it was found in (MotoLocationImagePlan::RELEASING): made in another role, under another standard plate, or from a source that is no longer the image in use. The standard plate is read first and the others against what stays chosen for it, so nothing is kept that hangs on an image taken back. No file is opened: a source whose file is missing blocks its images where they are used, it does not take their choice back
     */
    private function unfit(array $text, string $location): array
    {
        $place = (self::imagePlaces($text['evaluation'], (string) $text['stage']->id, $text['selection']) ?? [])[$location] ?? null;
        $picks = $place === null ? [] : array_filter(array_intersect_key((array) $text['selection']['images'], array_flip($place['plates'])), 'is_array');

        if ($picks === []) {
            return [];
        }

        $projectId = (string) $text['stage']->project_id;
        $images = $this->imagesOf($projectId, array_column($picks, 'image_id'));
        $artifacts = $this->artifactsOf($projectId, array_column($picks, 'artifact_id'));
        $released = [];
        $found = self::fileCheck(false);
        $order = $place['standard'] === null ? $place['plates'] : array_values(array_unique([$place['standard'], ...$place['plates']]));

        foreach ($order as $plate) {
            if (! isset($picks[$plate])) {
                continue;
            }

            $kept = ['selection' => ['images' => array_diff_key((array) $text['selection']['images'], $released)] + $text['selection']] + $text;
            $asked = [];
            $verdict = $this->judge($kept, [$location => $place], $plate, (array) ($images->get((string) ($picks[$plate]['image_id'] ?? ''))?->prompt_spec_json ?? []), $found, $asked, $images, $artifacts);

            if (in_array($verdict['state'], MotoLocationImagePlan::RELEASING, true)) {
                $released[$plate] = $verdict['state'];
            }
        }

        return $released;
    }

    /**
     * @param  list<mixed>  $ids
     * @return Collection<string, VideoArtifact> the artifacts of the project with these ids, read in one query and looked up by id
     */
    private function artifactsOf(string $projectId, array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== '')));

        return $ids === [] ? new Collection : $this->artifacts->findOwnedArtifactsByIds($projectId, $ids)->keyBy('id');
    }

    /**
     * @param  list<mixed>  $ids
     * @return Collection<string, VideoDesignImage> the Phase 5 images of the project with these ids, read in one query with the spec each was rendered from, and looked up by id; no image file is opened
     */
    private function imagesOf(string $projectId, array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== '')));

        return $ids === [] ? new Collection : $this->images->findPlateImagesByIds($projectId, $ids)->keyBy('id');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>> every chosen background with its source, the location it stands on, its state, the beats it serves, the changes and continuity the shot step must keep and the prompt it was rendered from; in a set rendered under the shared reference also the mechanism, the role of the plate, the standard plate of its place and the source the image was really made from. A set rendered plate by plate is handed on exactly as before
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
            ] + (isset($row['reference']) ? ['reference' => array_intersect_key($row['reference'], array_flip(['mechanism', 'role', 'location', 'standard_plate', 'source']))] : []);
        }

        return $manifest;
    }

    /**
     * @return array{0: array<string, mixed>, 1: ?string} the stage checked and planned again under the contract its own run pinned — its manifest, its context and the answer format that context names — never under the format the release writes now: a set made before the release took a newer format stays the set it was, with the same check, plan, prompts and hashes
     */
    private function evaluate(VideoPlanningStage $stage, LocationWorkflow $adapter): array
    {
        $meta = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null);
        $output = is_array($stage->output_json) ? $stage->output_json : [];

        if (! is_array($meta['manifest'] ?? null) || ! is_array($meta['context'] ?? null) || ! is_array($meta['source'] ?? null)) {
            return [[], 'Bản này không lưu đủ manifest, ngữ cảnh kiểm và nguồn của lượt chạy.'];
        }

        $format = is_string($meta['context']['format'] ?? null) ? $meta['context']['format'] : ($output['format'] ?? null);

        if (! is_string($format) || ! in_array($format, $adapter->formats(), true)) {
            return [[], 'Bản này theo định dạng '.json_encode($format).', bước này chỉ hỗ trợ '.implode(', ', $adapter->formats()).'.'];
        }

        if (($output['format'] ?? null) !== $format) {
            return [[], 'Bản này theo định dạng '.json_encode($output['format'] ?? null).', lượt chạy của nó ghim '.$format.'.'];
        }

        try {
            $context = $adapter->completeContext((string) $stage->project_id, $meta['context'], $meta['source']);
        } catch (RuntimeException $e) {
            return [[], 'Không bổ sung được dữ liệu thiết kế đã ghim của bản này: '.$e->getMessage()];
        }

        try {
            $evaluation = $this->evaluation->of((string) $stage->raw_response, $meta['manifest'], $context, $meta['source'], $adapter);
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

    /** @return array{version: int, text: ?array<string, mixed>, images: array<string, array<string, mixed>>, set: ?array<string, mixed>, standards: array<string, array<string, mixed>>} */
    private function selectionOf(VideoProject $project): array
    {
        $stored = $project->metadata_json[self::SELECTION_KEY] ?? null;
        $stored = is_array($stored) ? $stored : [];

        return [
            'version' => (int) ($stored['version'] ?? 0),
            'text' => is_array($stored['text'] ?? null) ? $stored['text'] : null,
            'images' => is_array($stored['images'] ?? null) ? $stored['images'] : [],
            'set' => is_array($stored['set'] ?? null) ? $stored['set'] : null,
            self::STANDARDS_KEY => is_array($stored[self::STANDARDS_KEY] ?? null) ? $stored[self::STANDARDS_KEY] : [],
            self::RELEASED_KEY => is_array($stored[self::RELEASED_KEY] ?? null) ? $stored[self::RELEASED_KEY] : [],
        ];
    }

    /** @param array<string, mixed> $selection the selection as it is kept; a set with no chosen standard plate and no choice taken back is stored without those keys, exactly as before */
    private function store(VideoProject $project, array $selection): void
    {
        foreach ([self::STANDARDS_KEY, self::RELEASED_KEY] as $key) {
            if (($selection[$key] ?? []) === []) {
                unset($selection[$key]);
            }
        }

        $metadata = $project->metadata_json ?? [];
        $metadata[self::SELECTION_KEY] = $selection;
        $project->metadata_json = $metadata;
        $project->save();
    }
}
