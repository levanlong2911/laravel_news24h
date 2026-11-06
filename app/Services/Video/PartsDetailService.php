<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoReviewDecision;
use App\Video\Media\MediaModelRegistry;
use App\Video\Media\OpenAiImagePricing;
use App\Video\ModelSheet\ModelSheetMaster;
use App\Video\Moto\MotoContract;
use App\Video\Moto\MotoReferenceCheck;
use App\Video\Moto\MotoReferencePlanner;
use App\Video\Queue\VideoQueues;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Opis\JsonSchema\Validator;
use RuntimeException;

class PartsDetailService implements QueuedImageService
{
    public const ROLE = 'parts_detail_crop';

    public const MASTER = 'parts_detail_master';

    public const GREY_ROLE = 'reference_geometry_source';

    public const GREY_TRANSFORM = 'greyscale-v1';

    public const SCHEMA_FILE = 'resources/ai/moto/schemas/MotoReferencePlan.schema.json';

    private const SELECTION = 'moto_reference_selection';

    private const DECISIONS = 'moto_reference_decisions';

    private const ACCEPTED = 'moto_reference_accepted';

    /** @var list<string> the statuses of a cell whose prompt can still change, so a person can still decide something for it */
    public const ANSWERABLE = ['prompt_check', 'prompt_confirm', 'prompt_failed', 'needs_review'];

    /** @var string the question of the note a person writes on what the model got wrong in a cell; a cell has one such note, which a person edits */
    public const CORRECTION_QUESTION = 'What the person reading this prompt asks to change';

    /** @var list<string> the image states of a master whose render is still on its way */
    private const IN_FLIGHT = ['candidate', 'queued', 'rendering'];

    private const MEMO_SIZE = 8;

    /** @var array<string, array<string, mixed>> the sources and plans built in this process, by project, project metadata and shots */
    private static array $memo = [];

    /** @var array<string, array{0: string, 1: string}> the tone and label of every reference status */
    public const STATUS_LABELS = [
        'approved' => ['ok', 'ĐÃ DUYỆT'],
        'rendering' => ['blue', 'ĐANG RENDER'],
        'awaiting_crop' => ['amber', 'CHỜ CẮT'],
        'awaiting_decision' => ['amber', 'CHỜ DUYỆT'],
        'prompt_ready' => ['ok', 'PROMPT ĐẠT'],
        'prompt_confirm' => ['amber', 'CHỜ XÁC NHẬN PROMPT'],
        'reviewing' => ['blue', 'ĐANG KIỂM PROMPT'],
        'prompt_check' => ['amber', 'CHỜ KIỂM PROMPT'],
        'prompt_failed' => ['dg', 'KIỂM PROMPT CHƯA ĐẠT'],
        'needs_review' => ['mute', 'CHƯA KIỂM PROMPT'],
        'blocked_surface' => ['dg', 'CẦN KIỂM BỀ MẶT'],
    ];

    public function supports(string $projectId): bool
    {
        $slug = VideoProject::query()->with('article.category')->find($projectId)?->article?->category?->slug;

        return MotoContract::is(config('video.superyacht.contracts.categories')[$slug ?? ''] ?? null);
    }

    /**
     * @return array{source: array<string, mixed>, plan: array<string, mixed>, hash: string} the reference plan code builds now from the approved shots, the approved model sheet and its design, and the planning decisions of the project
     *
     * @throws RuntimeException when a source is missing or the shots were written from another design
     */
    public function preview(string $projectId, ?array $shots = null): array
    {
        if (! $this->supports($projectId)) {
            throw new RuntimeException('Reference PARTS DETAIL chỉ áp dụng cho dự án moto.');
        }

        return $this->memo('preview', $projectId, $shots, function () use ($projectId, $shots): array {
            $sources = $this->sources($projectId, $shots);
            $plan = MotoReferencePlanner::plan($sources['shots'], $sources['design'], $sources['finished'], $sources['views'], $this->decisions($projectId), (array) config('video.moto.reference'), $sources['manifest']);
            $schema = json_decode((string) file_get_contents(base_path(self::SCHEMA_FILE)));

            if (! (new Validator)->validate(json_decode((string) json_encode($plan, JSON_THROW_ON_ERROR)), $schema)->isValid()) {
                throw new RuntimeException('Kế hoạch Reference không đạt schema '.MotoReferencePlanner::SCHEMA.'.');
            }

            return ['source' => $sources['manifest'], 'plan' => $plan, 'hash' => CanonicalJson::hash(['plan' => $plan, 'schema' => $schema]), 'views' => $sources['views'], 'shot_prompts' => array_map(static fn (array $shot): string => (string) ($shot['prompts']['image'] ?? ''), array_column($sources['shots'], null, 'id'))];
        });
    }

    /**
     * @param  ?list<array<string, mixed>>  $shots
     * @param  \Closure(): array<string, mixed>  $make
     * @return array<string, mixed> what $make builds, built once per project metadata and shots in this process; every approval and decision the plan reads is kept in the project metadata
     */
    private function memo(string $kind, string $projectId, ?array $shots, \Closure $make): array
    {
        $key = implode('|', [$kind, $projectId, md5((string) json_encode(VideoProject::query()->whereKey($projectId)->value('metadata_json'))), $shots === null ? '-' : md5((string) json_encode($shots))]);

        if (! array_key_exists($key, self::$memo)) {
            $value = $make();

            if (count(self::$memo) >= self::MEMO_SIZE) {
                array_shift(self::$memo);
            }

            self::$memo[$key] = $value;
        }

        return self::$memo[$key];
    }

    public function save(string $projectId, string $expectedHash): VideoPlanningStage
    {
        return DB::transaction(function () use ($projectId, $expectedHash): VideoPlanningStage {
            VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

            return $this->store($projectId, $this->preview($projectId), $expectedHash);
        });
    }

    /** @return array{0: ?VideoPlanningStage, 1: ?array<string, mixed>} the saved plan the project's sources build now, with that plan */
    public function active(string $projectId): array
    {
        try {
            $preview = $this->preview($projectId);
        } catch (RuntimeException) {
            return [null, null];
        }

        $stage = VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)->where('stage', PlanningStageName::REFERENCE_PROMPT->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->where('input_json->execution', MotoReferencePlanner::VERSION)
            ->where('input_json->fingerprint', $preview['hash'])->orderByDesc('planning_revision')->first();

        return [$stage, $preview];
    }

    /**
     * @return array<string, mixed> the saved plan, refused when the sources now build another one
     *
     * @throws RuntimeException
     */
    public function current(string $projectId, string $planId): array
    {
        $stage = VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)->where('stage', PlanningStageName::REFERENCE_PROMPT->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->where('input_json->execution', MotoReferencePlanner::VERSION)->find($planId);

        if ($stage === null) {
            throw new RuntimeException('Kế hoạch Reference không thuộc dự án hoặc chưa lập thành công.');
        }

        $preview = $this->preview($projectId);
        $this->same((string) ($stage->output_json['hash'] ?? ''), $preview['hash']);

        return $preview;
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  ?string  $ignore  a master image whose own render does not hold its cells
     * @return array<string, array{status: string, review: ?array<string, mixed>, last: ?array<string, mixed>, crop: ?array<string, mixed>, master: ?string}> where every planned reference stands now: approved with its crop, held by a master on its way or waiting for a decision, its prompt review ready, waiting for a check or failed, or not reviewed
     */
    public function state(string $projectId, array $preview, ?string $ignore = null): array
    {
        $references = array_column($preview['plan']['references'], null, 'id');
        $meta = (array) (VideoProject::query()->findOrFail($projectId)->metadata_json ?? []);
        $selection = (array) ($meta[self::SELECTION]['references'] ?? []);
        $accepted = (array) ($meta[self::ACCEPTED] ?? []);
        $reviews = $this->reviews($projectId);
        $held = $this->held($projectId, $references, $ignore);
        $running = $this->runningReview($projectId);
        $state = [];

        foreach ($references as $id => $reference) {
            $chosen = $selection[$id] ?? null;
            $crop = is_array($chosen) && ($chosen['fingerprint'] ?? null) === $reference['fingerprint'] ? $this->validSelection($projectId, $chosen) : null;
            $review = $reviews[$reference['fingerprint']] ?? null;
            $confirmed = $review !== null && ($accepted[$reference['fingerprint']]['stage_id'] ?? null) === $review['stage_id'];
            $status = match (true) {
                $reference['blocked'] !== [] => 'blocked_surface',
                $crop !== null => 'approved',
                isset($held[$id]) => $held[$id]['status'],
                in_array($reference['fingerprint'], $running, true) => 'reviewing',
                $review === null => 'needs_review',
                $review['review_status'] === MotoReferenceCheck::READY => 'prompt_ready',
                $review['review_status'] === MotoReferenceCheck::NEEDS_CONFIRM => $confirmed ? 'prompt_ready' : 'prompt_confirm',
                $review['review_status'] === MotoReferenceCheck::NEEDS_CHECK => 'prompt_check',
                default => 'prompt_failed',
            };
            $state[$id] = ['status' => $status, 'review' => $review, 'confirmed' => $confirmed ? $accepted[$reference['fingerprint']] : null, 'crop' => $crop, 'master' => $held[$id]['master'] ?? null];
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  array<string, array<string, mixed>>  $state
     * @return list<array<string, mixed>> the masters still to make: the references whose prompt passed its review, confirmed or still waiting for a person to confirm the lines the model rewrote, packed by input regime to render, then those whose prompt waits for its review packed the same way to review; each with its cells, the cells its review sends or a person confirms, whether it can render, its prompt, input image and hash
     */
    public function sheets(string $projectId, array $preview, array $state): array
    {
        $settings = (array) config('video.moto.reference');
        $references = array_column($preview['plan']['references'], null, 'id');
        $sheets = [];

        foreach ([['prompt_ready', 'prompt_confirm'], ['needs_review', 'prompt_failed']] as $statuses) {
            $open = array_values(array_filter($preview['plan']['references'], static fn (array $reference): bool => in_array($state[$reference['id']]['status'], $statuses, true)));

            foreach (MotoReferencePlanner::sheets($open, $settings) as $sheet) {
                $sheets[] = $this->describe($sheet, $references, $state, $preview);
            }
        }

        return $sheets;
    }

    /**
     * @param  list<string>  $cellIds  the cells of the master, in cell order
     * @return array<string, mixed> the master of these cells with its prompt and inputs
     *
     * @throws RuntimeException when a cell is not a planned reference, is already answered or held, or the cells mix input regimes
     */
    public function sheetOf(string $projectId, array $preview, array $cellIds, array $allowed, ?string $ignore = null): array
    {
        $references = array_column($preview['plan']['references'], null, 'id');
        $state = $this->state($projectId, $preview, $ignore);
        $settings = (array) config('video.moto.reference');

        if ($cellIds === [] || count(array_unique($cellIds)) !== count($cellIds)) {
            throw new RuntimeException('Danh sách ô không hợp lệ cho một master.');
        }

        foreach ($cellIds as $id) {
            if (! isset($references[$id])) {
                throw new RuntimeException("Ô {$id} không thuộc kế hoạch Reference hiện hành — tải lại trang.");
            }

            if (! in_array($state[$id]['status'], $allowed, true)) {
                throw new RuntimeException("Ô {$references[$id]['key']} đang ở trạng thái ".(self::STATUS_LABELS[$state[$id]['status']][1] ?? $state[$id]['status']).' — không làm thao tác này.');
            }
        }

        $regimes = array_unique(array_map(static fn (string $id): string => $references[$id]['regime'], $cellIds));
        $media = array_unique(array_map(static fn (string $id): string => $references[$id]['medium'], $cellIds));

        if (count($regimes) !== 1 || count($media) !== 1) {
            throw new RuntimeException('Một master chỉ chứa ô cùng bộ ảnh đầu vào (xám hoặc màu) và cùng cách thể hiện (chi tiết thật hoặc mô hình số).');
        }

        return $this->describe(MotoReferencePlanner::sheet($regimes[0], $media[0], $cellIds, $settings), $references, $state, $preview);
    }

    /**
     * @param  list<string>  $cellIds
     * @return array{preview: array<string, mixed>, regime: string, references: list<array<string, mixed>>} the saved plan and the planned references a review run of these cells sends
     */
    public function reviewCells(string $projectId, string $planId, array $cellIds): array
    {
        $preview = $this->current($projectId, $planId);
        $sheet = $this->sheetOf($projectId, $preview, $cellIds, ['needs_review', 'prompt_failed', 'prompt_check', 'prompt_confirm', 'reviewing']);

        return ['preview' => $preview, 'regime' => $sheet['regime'], 'references' => array_map(static fn (string $id): array => collect($preview['plan']['references'])->firstWhere('id', $id), $cellIds)];
    }

    /**
     * @param  list<string>  $cellIds
     * @param  ?array{id: string, name: string}  $requester
     * @return array{0: ?VideoDesignImage, 1: string} reason queued, rendered, rendered_stale, already_exists, or why nothing was sent
     */
    public function render(string $projectId, string $planId, array $cellIds, string $sheetHash, string $model, string $quality, string $operation, string $creator, ?array $requester = null): array
    {
        if (! Str::isUuid($operation)) {
            return [null, 'Mã lượt render không hợp lệ.'];
        }

        try {
            $spec = DB::transaction(function () use ($projectId, $planId, $cellIds, $sheetHash, $model, $quality, $operation): array {
                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
                $existing = VideoDesignImage::query()->where('project_id', $projectId)->where('image_type', self::MASTER)->where('prompt_spec_json->attempt', $operation)->first();

                if ($existing !== null) {
                    return ['existing' => (string) $existing->id];
                }

                $preview = $this->current($projectId, $planId);
                $sheet = $this->sheetOf($projectId, $preview, $cellIds, ['prompt_ready']);
                $this->same($sheetHash, $sheet['hash']);
                $entry = collect($this->models())->firstWhere('model', $model);

                if ($entry === null || ! in_array($quality, $entry['controls']['qualities'], true) || ! ImageModel::from($model)->supports(ImageQuality::from($quality))) {
                    throw new RuntimeException('Model hoặc chất lượng không hỗ trợ master Reference 4K.');
                }

                return [
                    'project_id' => $projectId, 'operation' => self::MASTER, 'task' => ModelSheetService::TASK,
                    'plan_id' => $planId, 'plan_hash' => $preview['hash'], 'sheet_id' => $sheet['id'], 'sheet_hash' => $sheet['hash'], 'attempt' => $operation,
                    'render_cells' => $sheet['cell_ids'], 'cells' => $sheet['pinned'], 'regime' => $sheet['regime'],
                    'layout' => $sheet['layout'], 'layout_sha256' => $sheet['layout_sha256'],
                    'prompt' => $sheet['prompt'], 'prompt_sha256' => hash('sha256', (string) $sheet['prompt']), 'sources' => $this->inputs($projectId, $preview, $sheet['regime']),
                    'provider' => 'openai', 'model' => $model, 'quality' => $quality, 'size' => (string) $sheet['layout']['size'], 'variations' => 1, 'pricing' => $entry['pricing'],
                ];
            });
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }

        if (isset($spec['existing'])) {
            return [VideoDesignImage::query()->find($spec['existing']), 'already_exists'];
        }

        $create = function () use ($projectId, $creator, $spec): array {
            [$image, $reason] = app(DesignImageStore::class)->createPartsDetailMaster($projectId, $creator, $spec);

            return $image === null ? [null, 'Không tạo được ô ảnh ('.$reason.').'] : [$image, $reason];
        };

        if (VideoQueues::enabled()) {
            return app(ImageRenderQueue::class)->createQueued(
                'parts_detail',
                $create,
                static fn (VideoDesignImage $image, string $reason): ?array => $reason === 'already_exists' && $image->status !== DesignImageStatus::CANDIDATE->value ? [$image, 'already_exists'] : null,
                $requester,
            );
        }

        [$image, $reason] = $create();

        if ($image === null || $reason === 'already_exists') {
            return [$image, $reason];
        }

        return array_slice($this->send((string) $image->id), 0, 2);
    }

    public function checkQueued(VideoDesignImage $image): ?string
    {
        try {
            $this->assertRenderSpec($image);

            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage().' — không gửi render; lập lại master nếu cần.';
        }
    }

    public function sendQueued(VideoDesignImage $image): array
    {
        return $this->send((string) $image->id);
    }

    /**
     * @return array<string, mixed> the plan the master was rendered for, refused when one of its cells is no longer planned with the same fingerprint and reviewed lines, its prompt no longer the one code assembles or an input image changed
     *
     * @throws RuntimeException
     */
    public function assertRenderSpec(VideoDesignImage $image): array
    {
        $spec = (array) $image->prompt_spec_json;

        if ($image->image_type !== self::MASTER) {
            throw new RuntimeException('Sai loại ảnh master Reference.');
        }

        $preview = $this->current((string) $image->project_id, (string) ($spec['plan_id'] ?? ''));
        $sheet = $this->sheetOf((string) $image->project_id, $preview, (array) ($spec['render_cells'] ?? []), ['prompt_ready'], (string) $image->id);

        if (CanonicalJson::hash($sheet['pinned']) !== CanonicalJson::hash($spec['cells'] ?? null) || ! hash_equals((string) $sheet['prompt'], (string) ($spec['prompt'] ?? ''))
            || CanonicalJson::hash($this->inputs((string) $image->project_id, $preview, $sheet['regime'])) !== CanonicalJson::hash($spec['sources'] ?? null)
            || ($spec['layout_sha256'] ?? null) !== $sheet['layout_sha256'] || ($spec['variations'] ?? null) !== 1) {
            throw new RuntimeException('Prompt, ô hoặc ảnh đầu vào của master không còn khớp kế hoạch Reference hiện hành.');
        }

        return $preview;
    }

    /** @return array{0: ?VideoDesignImage, 1: string, 2: ?array<string, mixed>} the master rendered and cut, with what the provider answered when it refused the call */
    private function send(string $imageId): array
    {
        [$image, $reason, $refusal] = app(DesignImageDirectRenderer::class)->renderNow($imageId);

        if ($reason !== 'rendered' || $image === null) {
            return [$image, $reason, $refusal];
        }

        $master = $image->artifacts()->orderBy('created_at')->orderBy('id')->first();

        if ($this->currentCells((string) $image->project_id, (array) $image->prompt_spec_json) === []) {
            return [$image, 'rendered_stale', null];
        }

        try {
            $this->process((string) $image->project_id, (string) $master?->id);
        } catch (RuntimeException $e) {
            return [$image, 'processing_failed|'.$e->getMessage(), null];
        }

        return [$image, 'rendered', null];
    }

    /**
     * @return string processed, already_processed
     *
     * @throws RuntimeException when the master is not the project's, its file changed, or its cells are no longer planned
     */
    public function process(string $projectId, string $artifactId): string
    {
        [$master, $image] = $this->master($projectId, $artifactId);
        $spec = (array) $image->prompt_spec_json;
        $references = $this->currentCells($projectId, $spec);
        $grid = new ModelSheetMaster((array) $spec['layout']);
        $crops = array_intersect_key($grid->crop($grid->decode($this->bytes($master))), array_flip((array) $spec['render_cells']));

        if ($references === []) {
            throw new RuntimeException('Các ô của master không còn trong kế hoạch Reference hiện hành — không cắt.');
        }

        return DB::transaction(function () use ($projectId, $master, $image, $spec, $crops): string {
            VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $valid = function (array $crop, VideoArtifact $locked) use ($crops, $master, $image): bool {
                $expected = $crops[$crop['view_id']] ?? null;

                if ($expected === null || $locked->sha256 !== $master->sha256 || $crop['master_sha256'] !== $locked->sha256
                    || $crop['x'] !== $expected['cell']['x'] || $crop['y'] !== $expected['cell']['y'] || $crop['width'] !== $expected['cell']['width']
                    || $crop['height'] !== $expected['cell']['height'] || $crop['sha256'] !== hash('sha256', $expected['png'])) {
                    return false;
                }

                try {
                    $artifact = VideoArtifact::query()->where('project_id', $master->project_id)->where('role', self::ROLE)->findOrFail($crop['id']);

                    if (($artifact->metadata_json['parts_detail_plan_id'] ?? '') !== $image->prompt_spec_json['plan_id']) {
                        return false;
                    }

                    $this->bytes($artifact);

                    return true;
                } catch (RuntimeException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
                    return false;
                }
            };

            return app(DesignImageStore::class)->storeSheetCrops($projectId, $master, (string) $spec['plan_id'], $crops,
                ['layout_sha256' => $spec['layout_sha256'], 'plan_hash' => $spec['plan_hash'], 'status' => 'processed'],
                static function (array $existing, VideoArtifact $locked) use ($crops, $valid): bool {
                    if (count($existing) !== count($crops) || count(array_unique(array_column($existing, 'view_id'))) !== count($crops)) {
                        return false;
                    }

                    foreach ($existing as $crop) {
                        if (! $valid($crop, $locked)) {
                            return false;
                        }
                    }

                    return true;
                }, self::ROLE, $valid);
        });
    }

    public function decide(string $projectId, string $artifactId, string $decision, ?string $reviewer): void
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new RuntimeException('Quyết định không hợp lệ.');
        }

        DB::transaction(function () use ($projectId, $artifactId, $decision, $reviewer): void {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $crop = VideoArtifact::query()->where('project_id', $projectId)->where('role', self::ROLE)->find($artifactId);

            if ($crop === null || isset($crop->metadata_json['superseded_at'])) {
                throw new RuntimeException('Crop không còn hiện hành hoặc không thuộc dự án.');
            }

            [$master, $image] = $this->master($projectId, (string) ($crop->metadata_json['master_artifact_id'] ?? ''));
            $spec = (array) $image->prompt_spec_json;
            $cellId = (string) ($crop->metadata_json['view_id'] ?? '');
            $pinned = collect((array) ($spec['cells'] ?? []))->firstWhere('id', $cellId);

            if ($pinned === null || ($crop->metadata_json['parts_detail_plan_id'] ?? '') !== ($spec['plan_id'] ?? null)) {
                throw new RuntimeException('Ô không thuộc master.');
            }

            if ($decision === 'approved') {
                if (! isset($this->currentCells($projectId, $spec)[$cellId])) {
                    throw new RuntimeException('Ô này không còn khớp kế hoạch Reference hiện hành (nguồn hoặc dữ kiện đổi) — không duyệt được.');
                }

                $this->verifyCrop($crop, $master, (array) $spec['layout']);
            }

            $review = VideoReviewDecision::create([
                'project_id' => $projectId, 'entity_type' => self::ROLE, 'entity_id' => $artifactId,
                'decision' => $decision, 'revision' => 1, 'reviewer_id' => $reviewer,
                'metadata_json' => ['reference_id' => $cellId, 'fingerprint' => $pinned['fingerprint'], 'sha256' => $crop->sha256, 'plan_id' => $spec['plan_id']],
            ]);
            $meta = $project->metadata_json ?? [];
            $selection = (array) ($meta[self::SELECTION]['references'] ?? []);

            if ($decision === 'approved') {
                $selection[$cellId] = ['fingerprint' => $pinned['fingerprint'], 'artifact_id' => $artifactId, 'review_id' => (string) $review->id, 'sha256' => $crop->sha256];
            } elseif (($selection[$cellId]['artifact_id'] ?? null) === $artifactId) {
                unset($selection[$cellId]);
            }

            $meta[self::SELECTION] = ['references' => $selection];
            $project->forceFill(['metadata_json' => $meta])->save();
        });
    }

    /**
     * @param  array<string, string>  $reviews  the review run a person read for each cell by reference id
     *
     * @throws RuntimeException when a cell does not wait for the confirmation or its newest review is not the run the person read
     */
    public function accept(string $projectId, string $planId, array $reviews, ?string $reviewer): void
    {
        if ($reviews === []) {
            throw new RuntimeException('Danh sách ô cần xác nhận không hợp lệ.');
        }

        DB::transaction(function () use ($projectId, $planId, $reviews, $reviewer): void {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $preview = $this->current($projectId, $planId);
            $references = array_column($preview['plan']['references'], null, 'id');
            $state = $this->state($projectId, $preview);
            $meta = $project->metadata_json ?? [];

            foreach ($reviews as $id => $stageId) {
                $id = (string) $id;

                if (! isset($references[$id]) || ($state[$id]['status'] ?? null) !== 'prompt_confirm') {
                    throw new RuntimeException('Ô '.($references[$id]['key'] ?? $id).' không chờ xác nhận prompt — tải lại trang.');
                }

                if ((string) $state[$id]['review']['stage_id'] !== (string) $stageId) {
                    throw new RuntimeException('Ô '.$references[$id]['key'].' đã có lượt kiểm mới hơn lượt anh xem — tải lại trang rồi xem lại.');
                }

                $meta[self::ACCEPTED][$references[$id]['fingerprint']] = ['stage_id' => (string) $stageId, 'reviewer' => $reviewer, 'at' => now()->toIso8601String()];
            }

            $project->forceFill(['metadata_json' => $meta])->save();
        });
    }

    /**
     * @param  string  $decision  use keeps the model sheet view for the need, refuse plans an image of its own
     * @return VideoPlanningStage the plan saved again with the decision
     */
    public function confirm(string $projectId, string $needId, string $view, string $decision, string $expectedHash): VideoPlanningStage
    {
        if (! in_array($decision, ['use', 'refuse'], true)) {
            throw new RuntimeException('Quyết định không hợp lệ.');
        }

        return DB::transaction(function () use ($projectId, $needId, $view, $decision, $expectedHash): VideoPlanningStage {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $preview = $this->preview($projectId);
            $this->same($expectedHash, $preview['hash']);
            $binding = collect($preview['plan']['bindings'])->firstWhere('need_id', $needId);

            if (($binding['type'] ?? null) !== 'model_sheet_candidate' || ! in_array($view, (array) $binding['candidates'], true)) {
                throw new RuntimeException('Nhu cầu này không chờ xác nhận ảnh model sheet '.$view.'.');
            }

            $meta = $project->metadata_json ?? [];
            $meta[self::DECISIONS]['candidates'][$needId] = ['decision' => $decision, 'view' => $view];
            $project->forceFill(['metadata_json' => $meta])->save();
            $next = $this->preview($projectId);

            return $this->store($projectId, $next, $next['hash']);
        });
    }

    /** @return VideoPlanningStage the plan saved again with the need split off into an image of its own */
    public function split(string $projectId, string $needId, string $expectedHash): VideoPlanningStage
    {
        return DB::transaction(function () use ($projectId, $needId, $expectedHash): VideoPlanningStage {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $preview = $this->preview($projectId);
            $this->same($expectedHash, $preview['hash']);
            $binding = collect($preview['plan']['bindings'])->firstWhere('need_id', $needId);
            $reference = $binding === null || $binding['type'] !== 'reference' ? null : collect($preview['plan']['references'])->firstWhere('id', $binding['source']);

            if ($reference === null || count(array_unique(array_map(static fn (string $id): string => (string) strstr($id, '#', true), (array) $reference['needs']))) < 2) {
                throw new RuntimeException('Chỉ tách được nhu cầu đang dùng chung một ảnh với shot khác.');
            }

            $meta = $project->metadata_json ?? [];
            $meta[self::DECISIONS]['splits'] = array_values(array_unique([...(array) ($meta[self::DECISIONS]['splits'] ?? []), $needId]));
            $project->forceFill(['metadata_json' => $meta])->save();
            $next = $this->preview($projectId);

            return $this->store($projectId, $next, $next['hash']);
        });
    }

    /**
     * @param  list<array{question: string, answer: string}>  $decisions  what the sources leave open or what to change, each with what a person decided
     * @return VideoPlanningStage the plan saved again with the decisions as facts of the cell for its present sources, each replacing the earlier answer to its question, and every answer kept in the history with who decided, when and for which sources; the design stays as approved
     *
     * @throws RuntimeException when the prompt of the cell can no longer change
     */
    public function answer(string $projectId, string $referenceId, array $decisions, string $expectedHash, ?string $reviewer): VideoPlanningStage
    {
        return DB::transaction(function () use ($projectId, $referenceId, $decisions, $expectedHash, $reviewer): VideoPlanningStage {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $preview = $this->preview($projectId);
            $this->same($expectedHash, $preview['hash']);
            $reference = collect($preview['plan']['references'])->firstWhere('id', $referenceId);

            if ($reference === null || ! in_array($this->state($projectId, $preview)[$referenceId]['status'], self::ANSWERABLE, true)) {
                throw new RuntimeException('Ô này không còn nhận quyết định (đã có prompt đạt, đang render hoặc đã có ảnh) — tải lại trang.');
            }

            $meta = $project->metadata_json ?? [];

            foreach ($decisions as $decision) {
                $meta[self::DECISIONS]['answers'][$referenceId][] = ['question' => $decision['question'], 'answer' => $decision['answer'], 'reviewer' => $reviewer, 'at' => now()->toIso8601String(), 'source' => $reference['source_fingerprint']];
            }

            $project->forceFill(['metadata_json' => $meta])->save();
            $next = $this->preview($projectId);

            return $this->store($projectId, $next, $next['hash']);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $shots  the shots of a derived shot plan
     * @param  list<string>  $finished  the finished build states of that plan
     * @return ?array<string, array{candidate: ?array{source: string, artifact_id: ?string}, problem: ?string}> for every design and identity need by its id, the image that answers it now or why none does, planned from the approved model sheet and its design; null when no model sheet is approved or the plan cannot be built
     */
    public function bindings(string $projectId, array $shots, array $finished): ?array
    {
        if (! $this->supports($projectId)) {
            return null;
        }

        try {
            $preview = $this->memo('bindings', $projectId, $shots, function () use ($projectId, $shots, $finished): array {
                $sheet = $this->sheetSources($projectId);

                return ['plan' => MotoReferencePlanner::plan($shots, $sheet['design'], $finished, $sheet['views'], $this->decisions($projectId), (array) config('video.moto.reference'), $sheet['manifest']), 'views' => $sheet['views']];
            });
        } catch (RuntimeException) {
            return null;
        }

        $views = $preview['views'];
        $references = array_column($preview['plan']['references'], null, 'id');
        $state = $this->state($projectId, $preview);
        $kinds = array_column($preview['plan']['needs'], 'kind', 'id');
        $result = [];

        foreach ($preview['plan']['bindings'] as $binding) {
            if (! in_array($kinds[$binding['need_id']] ?? null, ['design', 'identity'], true)) {
                continue;
            }

            $result[$binding['need_id']] = match ($binding['type']) {
                'model_sheet' => ['candidate' => ['source' => (string) $binding['source'], 'artifact_id' => $views[$binding['source']]['artifact_id'] ?? null], 'problem' => null],
                'model_sheet_candidate' => ['candidate' => null, 'problem' => 'chờ xác nhận dùng ảnh model sheet '.$binding['source'].' (part chỉ thấy một phần hoặc chưa chắc chắn)'],
                'reference' => ($state[$binding['source']]['crop'] ?? null) !== null
                    ? ['candidate' => ['source' => (string) $references[$binding['source']]['key'], 'artifact_id' => (string) $state[$binding['source']]['crop']['id']], 'problem' => null]
                    : ['candidate' => null, 'problem' => 'chờ ảnh Reference '.$references[$binding['source']]['key'].' ('.(self::STATUS_LABELS[$state[$binding['source']]['status']][1] ?? $state[$binding['source']]['status']).')'],
                default => ['candidate' => null, 'problem' => match ($binding['rule']) {
                    'identity_without_detail' => 'nhận diện cần ảnh chi tiết chứa mọi part trong khung — chưa có',
                    default => 'model sheet chưa có ảnh đã duyệt',
                }],
            };
        }

        return $result;
    }

    /** @return array<string, mixed> everything the Reference screen shows */
    public function panel(string $projectId): array
    {
        $error = null;
        [$stage, $preview] = [null, null];

        try {
            $preview = $this->preview($projectId);
            [$stage] = $this->active($projectId);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }

        $state = $preview === null ? [] : $this->state($projectId, $preview);
        $references = $preview === null ? [] : array_column($preview['plan']['references'], null, 'id');
        $sheets = $preview === null ? [] : $this->sheets($projectId, $preview, $state);
        $writer = app(ReferenceWriterService::class);

        foreach ($sheets as &$sheet) {
            $sheet['estimate'] = $stage === null || $sheet['review_cells'] === [] ? null : $writer->estimate($projectId, (string) $stage->id, $sheet['review_cells']);

            foreach ($sheet['cells'] as &$cell) {
                $cell['estimate'] = $stage === null || $cell['state']['status'] !== 'prompt_confirm' ? null : $writer->estimate($projectId, (string) $stage->id, [$cell['reference']['id']]);
            }

            unset($cell);
        }

        unset($sheet);
        $checks = [];

        foreach ($state as $id => $entry) {
            if (in_array($entry['status'], ['prompt_check', 'blocked_surface'], true)) {
                $checks[] = ['reference' => $references[$id], 'state' => $entry, 'estimate' => $stage === null || $entry['status'] !== 'prompt_check' ? null : $writer->estimate($projectId, (string) $stage->id, [$id])];
            }
        }

        $images = VideoDesignImage::query()->where('project_id', $projectId)->where('image_type', self::MASTER)->with('artifacts')->latest()->get();
        $masters = [];

        foreach ($images as $image) {
            $artifacts = [];

            foreach ($image->artifacts as $artifact) {
                $artifacts[] = ['artifact' => $artifact, 'crops' => app(DesignImageStore::class)->sheetCrops($projectId, $artifact, self::ROLE), 'processing' => $artifact->metadata_json['parts_detail'] ?? null];
            }

            $masters[] = ['image' => $image, 'artifacts' => $artifacts, 'task' => ImageRenderQueue::taskOf($image)];
        }

        $decisions = VideoReviewDecision::query()->where('project_id', $projectId)->where('entity_type', self::ROLE)
            ->orderByDesc('created_at')->orderByDesc('id')->get()->groupBy('entity_id');
        $models = $this->models();
        $prices = [];

        foreach ($models as $model) {
            foreach ($model['controls']['qualities'] as $quality) {
                $prices[$model['model']][$quality] = app(OpenAiImagePricing::class)->unitFor($model['model'], $quality);
            }
        }

        $counts = ['done' => 0, 'pending' => 0, 'missing' => 0];

        foreach ($preview['plan']['bindings'] ?? [] as $binding) {
            $kind = collect($preview['plan']['needs'])->firstWhere('id', $binding['need_id'])['kind'] ?? null;

            if (! in_array($kind, ['design', 'identity'], true)) {
                continue;
            }

            $status = $binding['type'] === 'reference' ? ($state[$binding['source']]['status'] ?? null) : null;
            $bucket = match (true) {
                $binding['type'] === 'model_sheet', $status === 'approved' => 'done',
                in_array($status, ['rendering', 'awaiting_crop', 'awaiting_decision'], true), $binding['type'] === 'model_sheet_candidate' => 'pending',
                default => 'missing',
            };
            $counts[$bucket]++;
        }

        $selectedCrops = [];

        foreach ($state as $id => $entry) {
            if ($entry['crop'] !== null) {
                $selectedCrops[$entry['crop']['id']] = $id;
            }
        }

        return [
            'error' => $error,
            'preview' => $preview,
            'stage' => $stage,
            'state' => $state,
            'references' => $references,
            'sheets' => $sheets,
            'checks' => $checks,
            'masters' => $masters,
            'decisions' => $decisions,
            'selected_crops' => $selectedCrops,
            'models' => $models,
            'prices' => $prices,
            'counts' => $counts,
            'shot_prompts' => $preview['shot_prompts'] ?? [],
            'labels' => self::STATUS_LABELS,
            'review' => app(ReferenceWriterService::class)->summary($projectId),
        ];
    }

    /** @return list<array<string, mixed>> the image models that render every offered master size with OpenAI */
    public function models(): array
    {
        $sizes = array_values(array_unique(array_column((array) config('video.moto.reference.layouts'), 'size')));

        return array_values(array_filter(app(MediaModelRegistry::class)->forTask(ModelSheetService::TASK),
            static fn (array $m): bool => $m['provider'] === 'openai' && array_diff($sizes, $m['controls']['sizes']) === []));
    }

    /**
     * @param  ?list<array<string, mixed>>  $shots  the shots to plan, the approved shot plan's when null
     * @return array{shots: list<array<string, mixed>>, design: array<string, mixed>, finished: list<string>, views: array<string, array<string, mixed>>, manifest: array<string, mixed>}
     */
    private function sources(string $projectId, ?array $shots = null): array
    {
        $approved = app(ShotSourceService::class)->approvedSource($projectId);
        $sheet = $this->sheetSources($projectId);
        $shotStage = VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)->findOrFail($approved['stage_id']);
        $shotSource = app(ShotSourceService::class)->source($shotStage);
        $scene = app(SceneSourceService::class)->sceneStage($projectId, (string) ($shotSource['scenes']['stage_id'] ?? ''));
        $designSource = $scene === null ? [] : (PlanningStageStore::metadataOf($scene->input_json)['source']['design'] ?? []);

        if (($designSource['design_stage_id'] ?? '') !== $sheet['manifest']['design_stage_id'] || ($designSource['design_raw_sha256'] ?? '') !== $sheet['manifest']['design_raw_sha256']) {
            throw new RuntimeException('Thiết kế của kế hoạch shot không khớp thiết kế của model sheet đã duyệt.');
        }

        return [
            'shots' => $shots ?? (array) $approved['plan']['shots'],
            'design' => $sheet['design'],
            'finished' => array_values((array) ($approved['plan']['finished_states'] ?? [])),
            'views' => $sheet['views'],
            'manifest' => ['shots' => $approved['token'], 'shot_stage_id' => (string) $approved['stage_id']] + $sheet['manifest'],
        ];
    }

    /** @return array{design: array<string, mixed>, views: array<string, array<string, mixed>>, manifest: array<string, mixed>} the approved model sheet with its design and the approved crop of every view */
    private function sheetSources(string $projectId): array
    {
        $sheet = app(ModelSheetService::class)->approvedSheet($projectId, true);
        $designStage = VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)->findOrFail($sheet['design_stage_id']);
        $raw = (string) $designStage->raw_response;
        $design = json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['data'];
        $review = VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)->findOrFail($sheet['review_stage_id']);
        $crops = [];

        foreach ((array) ($review->output_json['crops'] ?? []) as $crop) {
            if (is_string($crop['view_id'] ?? null) && is_string($crop['artifact_id'] ?? null)) {
                $crops[$crop['view_id']] = $crop['artifact_id'];
            }
        }

        $views = [];

        foreach ((array) ($design['model_sheet']['views'] ?? []) as $view) {
            if (isset($crops[$view['id']])) {
                $views[(string) $view['id']] = [
                    'artifact_id' => $crops[$view['id']],
                    'camera' => (array) ($view['camera'] ?? []),
                    'visible' => array_values((array) ($view['visible'] ?? [])),
                    'partial' => array_values((array) ($view['partial'] ?? [])),
                    'uncertain' => array_values((array) ($view['uncertain'] ?? [])),
                ];
            }
        }

        return [
            'design' => $design,
            'views' => $views,
            'manifest' => [
                'design_stage_id' => (string) $designStage->id,
                'design_raw_sha256' => hash('sha256', $raw),
                'model_sheet_set_id' => (string) $sheet['set_id'],
                'model_sheet_image_id' => (string) $sheet['master_image_id'],
                'model_sheet_artifact_id' => (string) $sheet['master_artifact_id'],
                'model_sheet_sha256' => (string) $sheet['master_artifact_sha256'],
                'planner' => MotoReferencePlanner::VERSION,
                'surface' => \App\Video\Moto\MotoReferenceSurface::VERSION,
                'prompt' => MotoReferencePlanner::PROMPT_VERSION,
                'schema' => MotoReferencePlanner::SCHEMA,
                'settings' => CanonicalJson::hash(array_intersect_key((array) config('video.moto.reference'), array_flip(['share_angle_deg', 'layouts', 'surface_groups', 'finish_labels', 'prompt']))),
            ],
        ];
    }

    /** @return array{splits?: list<string>, candidates?: array<string, array{decision: string, view: string}>, answers?: array<string, list<array{question: string, answer: string, reviewer: ?string, at: string, source: string}>>} */
    private function decisions(string $projectId): array
    {
        return (array) (VideoProject::query()->find($projectId)?->metadata_json[self::DECISIONS] ?? []);
    }

    /** @param  array<string, mixed>  $preview */
    private function store(string $projectId, array $preview, string $expectedHash): VideoPlanningStage
    {
        $this->same($expectedHash, $preview['hash']);
        $store = app(PlanningStageStore::class);
        [$stage, $token, $reason] = $store->claimProjectStage($projectId, PlanningStageName::REFERENCE_PROMPT,
            ['execution' => MotoReferencePlanner::VERSION, 'fingerprint' => $preview['hash']], false);

        if ($token !== null) {
            $saved = array_intersect_key($preview, array_flip(['source', 'plan', 'hash']));

            if (! $store->finishSucceeded((string) $stage->id, $token, (string) json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $saved)) {
                throw new RuntimeException('Không lưu được kế hoạch Reference.');
            }
        } elseif ($stage === null || $stage->status !== VideoPlanningStageStatus::SUCCEEDED->value) {
            throw new RuntimeException('Kế hoạch Reference đang được xử lý: '.$reason);
        }

        return $stage->fresh();
    }

    /**
     * @param  array{id: string, regime: string, medium: string, cell_ids: list<string>, layout: array<string, mixed>, layout_sha256: string}  $sheet
     * @param  array<string, array<string, mixed>>  $references
     * @param  array<string, array<string, mixed>>  $state
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function describe(array $sheet, array $references, array $state, array $preview): array
    {
        $settings = (array) config('video.moto.reference');
        $ready = true;
        $reviewed = true;
        $lines = [];
        $draft = [];
        $pinned = [];
        $confirm = [];

        foreach ($sheet['cell_ids'] as $id) {
            $review = $state[$id]['review'] ?? null;
            $passed = in_array($state[$id]['status'], ['prompt_ready', 'prompt_confirm'], true) && $review !== null;
            $ready = $ready && $passed && $state[$id]['status'] === 'prompt_ready';
            $reviewed = $reviewed && $passed;
            $lines[$id] = $passed ? $review['lines'] : MotoReferencePlanner::draftLines($references[$id]);
            $draft[$id] = MotoReferencePlanner::draftLines($references[$id]);
            $pinned[] = ['id' => $id, 'fingerprint' => $references[$id]['fingerprint'], 'review_stage_id' => $passed ? $review['stage_id'] : null];

            if ($state[$id]['status'] === 'prompt_confirm') {
                $confirm[$id] = (string) $review['stage_id'];
            }
        }

        $error = null;
        $reviewedPrompt = null;
        $draftPrompt = null;

        try {
            $draftPrompt = MotoReferencePlanner::masterPrompt($sheet, $references, $draft, $settings);
            $reviewedPrompt = $reviewed ? MotoReferencePlanner::masterPrompt($sheet, $references, $lines, $settings) : null;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
            $ready = false;
        }

        $prompt = $ready ? $reviewedPrompt : null;

        return $sheet + [
            'cells' => array_map(static fn (string $id): array => ['reference' => $references[$id], 'state' => $state[$id]], $sheet['cell_ids']),
            'review_cells' => array_values(array_filter($sheet['cell_ids'], static fn (string $id): bool => in_array($state[$id]['status'], ['needs_review', 'prompt_failed'], true))),
            'confirm_cells' => $confirm,
            'renderable' => $ready,
            'prompt' => $prompt,
            'reviewed_prompt' => $reviewedPrompt,
            'draft_prompt' => $draftPrompt,
            'prompt_error' => $error,
            'pinned' => $pinned,
            'hash' => CanonicalJson::hash(['sheet' => array_intersect_key($sheet, array_flip(['id', 'regime', 'cell_ids', 'layout_sha256'])), 'pinned' => $pinned, 'prompt' => $prompt, 'plan' => $preview['hash']]),
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return list<array{position: int, artifact_id: string, candidate_id: ?string, sha256: string, kind: string}> the input image of a master: the approved model sheet master, or its greyscale copy for a grey master
     */
    private function inputs(string $projectId, array $preview, string $regime): array
    {
        $source = $preview['source'];
        $master = VideoArtifact::query()->where('project_id', $projectId)->findOrFail($source['model_sheet_artifact_id']);

        if ((string) $master->sha256 !== (string) $source['model_sheet_sha256']) {
            throw new RuntimeException('Master model sheet đã đổi so với kế hoạch Reference.');
        }

        if ($regime === 'colour') {
            return [['position' => 0, 'artifact_id' => (string) $master->id, 'candidate_id' => (string) $master->design_image_id, 'sha256' => (string) $master->sha256, 'kind' => 'model_sheet_master']];
        }

        $grey = app(DesignImageStore::class)->greyscaleSource($projectId, $master, self::GREY_ROLE, self::GREY_TRANSFORM);

        return [['position' => 0, 'artifact_id' => (string) $grey->id, 'candidate_id' => null, 'sha256' => (string) $grey->sha256, 'kind' => 'model_sheet_greyscale']];
    }

    /** @return array<string, array<string, mixed>> for every cell fingerprint the outcome of the newest finished review run that sent it: its checked cell when the whole answer passed the envelope gate, otherwise a failed entry with why; an older outcome never stands in for a newer one */
    private function reviews(string $projectId): array
    {
        $latest = [];
        $stages = VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)->where('stage', PlanningStageName::REFERENCE_REVIEW->value)
            ->whereIn('status', [VideoPlanningStageStatus::SUCCEEDED->value, VideoPlanningStageStatus::FAILED->value])
            ->whereNull('input_json->orphan_of_stage_id')->orderByDesc('planning_revision')->get();

        foreach ($stages as $stage) {
            $derived = is_array($stage->output_json['derived'] ?? null) ? $stage->output_json['derived'] : null;
            $envelope = $derived === null ? null : ($derived['envelope'] ?? ['Kết quả kiểm không có cổng kiểm chung của phản hồi.']);
            $usable = $stage->status === VideoPlanningStageStatus::SUCCEEDED->value && $envelope === [];
            $checked = $usable ? array_column((array) ($derived['cells'] ?? []), null, 'fingerprint') : [];
            $reason = $envelope !== null && $envelope !== [] ? 'Phản hồi không dùng được cho ô nào: '.implode(' ', (array) $envelope) : null;

            foreach ((array) ($stage->input_json['cells'] ?? []) as $sent) {
                $fingerprint = (string) ($sent['fingerprint'] ?? '');

                if ($fingerprint === '' || isset($latest[$fingerprint])) {
                    continue;
                }

                $latest[$fingerprint] = ($checked[$fingerprint] ?? [
                    'cell_id' => (string) ($sent['id'] ?? ''),
                    'fingerprint' => $fingerprint,
                    'edit_status' => null,
                    'review_status' => MotoReferenceCheck::FAILED,
                    'lines' => [],
                    'changes' => [],
                    'gaps' => [],
                    'issues' => [(string) ($reason ?? $stage->error_message ?? $stage->output_json['check']['error'] ?? 'Lượt kiểm không cho kết quả ô này.')],
                ]) + ['stage_id' => (string) $stage->id, 'revision' => (int) $stage->planning_revision];
            }
        }

        return $latest;
    }

    /** @return list<string> the cell fingerprints a review run still waiting or running sends */
    private function runningReview(string $projectId): array
    {
        $fingerprints = [];

        foreach (VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)->where('stage', PlanningStageName::REFERENCE_REVIEW->value)
            ->whereIn('status', [VideoPlanningStageStatus::PENDING->value, VideoPlanningStageStatus::RUNNING->value])->whereNotNull('claim_token')->get() as $stage) {
            foreach ((array) ($stage->input_json['cells'] ?? []) as $cell) {
                $fingerprints[] = (string) ($cell['fingerprint'] ?? '');
            }
        }

        return $fingerprints;
    }

    /**
     * @param  array<string, array<string, mixed>>  $references
     * @return array<string, array{status: string, master: string}> the planned cells a master holds: on its way, waiting to be cut or waiting for a decision on its crop
     */
    private function held(string $projectId, array $references, ?string $ignore): array
    {
        $held = [];
        $images = VideoDesignImage::query()->where('project_id', $projectId)->where('image_type', self::MASTER)
            ->whereIn('status', [...self::IN_FLIGHT, 'rendered'])->with('artifacts')->get();

        foreach ($images as $image) {
            if ((string) $image->id === $ignore) {
                continue;
            }

            $cells = array_column((array) ($image->prompt_spec_json['cells'] ?? []), 'fingerprint', 'id');

            foreach ($cells as $id => $fingerprint) {
                if (($references[$id]['fingerprint'] ?? null) !== $fingerprint) {
                    continue;
                }

                if (in_array($image->status, self::IN_FLIGHT, true)) {
                    $held[$id] = ['status' => 'rendering', 'master' => (string) $image->id];

                    continue;
                }

                foreach ($image->artifacts as $master) {
                    $crop = collect(app(DesignImageStore::class)->sheetCrops($projectId, $master, self::ROLE))->firstWhere('view_id', $id);

                    if ($crop === null) {
                        $held[$id] = ['status' => 'awaiting_crop', 'master' => (string) $image->id];

                        continue;
                    }

                    $decision = VideoReviewDecision::query()->where('project_id', $projectId)->where('entity_type', self::ROLE)->where('entity_id', $crop['id'])
                        ->orderByDesc('created_at')->orderByDesc('id')->value('decision');

                    if ($decision === null) {
                        $held[$id] = ['status' => 'awaiting_decision', 'master' => (string) $image->id];
                    }
                }
            }
        }

        return $held;
    }

    /**
     * @param  array{artifact_id?: string, review_id?: string, sha256?: string}  $chosen
     * @return ?array<string, mixed> the approved crop while it is current, whole and still approved
     */
    private function validSelection(string $projectId, array $chosen): ?array
    {
        try {
            $crop = VideoArtifact::query()->where('project_id', $projectId)->where('role', self::ROLE)->find($chosen['artifact_id'] ?? null);
            $review = VideoReviewDecision::query()->where('project_id', $projectId)->where('entity_type', self::ROLE)->where('decision', 'approved')->find($chosen['review_id'] ?? null);

            if ($crop === null || $review === null || isset($crop->metadata_json['superseded_at']) || (string) $review->entity_id !== (string) $crop->id
                || ($review->metadata_json['sha256'] ?? null) !== $crop->sha256 || ($chosen['sha256'] ?? null) !== $crop->sha256) {
                return null;
            }

            [$master, $image] = $this->master($projectId, (string) ($crop->metadata_json['master_artifact_id'] ?? ''));
            $this->verifyCrop($crop, $master, (array) $image->prompt_spec_json['layout']);

            return ['id' => (string) $crop->id, 'url' => route('video-artifacts.show', $crop->id), 'sha256' => (string) $crop->sha256, 'master_image_id' => (string) $image->id];
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $spec  the render spec of a master
     * @return array<string, array<string, mixed>> the cells of the master still planned now with the fingerprint they were rendered for
     */
    private function currentCells(string $projectId, array $spec): array
    {
        try {
            $preview = $this->preview($projectId);
        } catch (RuntimeException) {
            return [];
        }

        $references = array_column($preview['plan']['references'], null, 'id');
        $current = [];

        foreach ((array) ($spec['cells'] ?? []) as $cell) {
            if (($references[$cell['id']]['fingerprint'] ?? null) === $cell['fingerprint']) {
                $current[$cell['id']] = $references[$cell['id']];
            }
        }

        return $current;
    }

    /** @return array{0: VideoArtifact, 1: VideoDesignImage} */
    private function master(string $projectId, string $artifactId): array
    {
        $master = VideoArtifact::query()->where('project_id', $projectId)->find($artifactId);
        $image = $master === null ? null : VideoDesignImage::query()->where('project_id', $projectId)->where('image_type', self::MASTER)->find($master->design_image_id);

        if ($image === null) {
            throw new RuntimeException('Master không thuộc Reference của dự án.');
        }

        return [$master, $image];
    }

    private function bytes(VideoArtifact $artifact): string
    {
        $disk = Storage::disk($artifact->storage_disk);

        if (! $disk->exists($artifact->storage_path)) {
            throw new RuntimeException('File ảnh không còn: '.$artifact->id);
        }

        $bytes = (string) $disk->get($artifact->storage_path);
        $this->same((string) $artifact->sha256, hash('sha256', $bytes));

        return $bytes;
    }

    /** @param  array<string, mixed>  $layout */
    private function verifyCrop(VideoArtifact $crop, VideoArtifact $master, array $layout): void
    {
        $grid = new ModelSheetMaster($layout);
        [$width, $height] = $grid->canvas();
        $masterSize = @getimagesizefromstring($this->bytes($master));
        $bytes = $this->bytes($crop);
        $cell = collect($grid->cells())->firstWhere('id', $crop->metadata_json['view_id'] ?? '');
        $size = @getimagesizefromstring($bytes);

        if ($masterSize === false || $masterSize[0] !== $width || $masterSize[1] !== $height
            || $cell === null || $size === false || $size[0] !== $cell['width'] || $size[1] !== $cell['height']
            || $crop->width !== $cell['width'] || $crop->height !== $cell['height']
            || ($crop->metadata_json['master_sha256'] ?? '') !== $master->sha256
            || ($crop->metadata_json['x'] ?? null) !== $cell['x'] || ($crop->metadata_json['y'] ?? null) !== $cell['y']) {
            throw new RuntimeException('Crop không khớp kích thước, tọa độ hoặc master.');
        }
    }

    private function same(string $expected, string $actual): void
    {
        if ($expected === '' || ! hash_equals($expected, $actual)) {
            throw new RuntimeException('Nguồn hoặc nội dung Reference đã đổi. Tải lại trang rồi làm lại từ kế hoạch hiện hành.');
        }
    }
}
