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
            $sources = ModelSheetService::readOnce(fn (): array => $this->sources($projectId, $shots));
            $plan = MotoReferencePlanner::partPlan($sources['shots'], $sources['design'], $sources['finished'], $sources['views'], (array) config('video.moto.reference'), $sources['manifest']);
            $schema = json_decode((string) file_get_contents(base_path(self::SCHEMA_FILE)));

            if (! (new Validator)->validate(json_decode((string) json_encode($plan, JSON_THROW_ON_ERROR)), $schema)->isValid()) {
                throw new RuntimeException('Kế hoạch Reference không đạt schema '.MotoReferencePlanner::PART_SCHEMA.'.');
            }

            return ['source' => $sources['manifest'], 'plan' => $plan, 'hash' => CanonicalJson::hash(['plan' => $plan, 'schema' => $schema]), 'views' => $sources['views'], 'shot_prompts' => array_map(static fn (array $shot): string => (string) ($shot['prompts']['image'] ?? ''), array_column($sources['shots'], null, 'id'))];
        });
    }

    /**
     * @param  ?list<array<string, mixed>>  $shots
     * @param  \Closure(): array<string, mixed>  $make
     * @return array<string, mixed> what $make builds, built once per project metadata, moto settings and shots in this process; every approval and decision the plan reads is kept in the project metadata, and the prompt is put together from the settings
     */
    private function memo(string $kind, string $projectId, ?array $shots, \Closure $make): array
    {
        $key = implode('|', [$kind, $projectId, md5((string) json_encode(VideoProject::query()->whereKey($projectId)->value('metadata_json'))), md5((string) json_encode(config('video.moto'))), $shots === null ? '-' : md5((string) json_encode($shots))]);

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
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->where('input_json->execution', MotoReferencePlanner::PART_VERSION)
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
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->where('input_json->execution', MotoReferencePlanner::PART_VERSION)->find($planId);

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
        $reviewed = array_filter($references, static fn (array $reference): bool => ($reference['kind'] ?? null) !== 'part') !== [];
        $reviews = $reviewed ? $this->reviews($projectId) : [];
        $held = $this->held($projectId, $references, $ignore);
        $running = $reviewed ? $this->runningReview($projectId) : [];
        $crops = $this->validSelections($projectId, array_filter(array_intersect_key($selection, $references), static fn (mixed $chosen, string $id): bool => is_array($chosen) && ($chosen['fingerprint'] ?? null) === $references[$id]['fingerprint'], ARRAY_FILTER_USE_BOTH));
        $state = [];

        foreach ($references as $id => $reference) {
            $crop = $crops[$id] ?? null;
            $review = $reviews[$reference['fingerprint']] ?? null;
            $confirmed = $review !== null && ($accepted[$reference['fingerprint']]['stage_id'] ?? null) === $review['stage_id'];
            $status = match (true) {
                $reference['blocked'] !== [] => 'blocked_surface',
                $crop !== null => 'approved',
                isset($held[$id]) => $held[$id]['status'],
                ($reference['kind'] ?? null) === 'part' => 'prompt_ready',
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
     * @return list<array<string, mixed>> the one master still to make: every cell with no approved crop and no master holding it, on the grid a person chose, whether it can render, its prompt and hash; none when no cell is left
     */
    public function sheets(string $projectId, array $preview, array $state): array
    {
        $references = array_column($preview['plan']['references'], null, 'id');
        $open = array_values(array_filter(array_keys($references), static fn (string $id): bool => $state[$id]['status'] === 'prompt_ready'));

        if ($open === []) {
            return [];
        }

        return [$this->describe(MotoReferencePlanner::partSheet($open, $this->gridChoice($projectId)), $references, $state, $preview)];
    }

    /**
     * @param  list<string>  $cellIds  the cells of the master, in cell order
     * @param  ?array<string, mixed>  $layout  the layout a render pinned; the grid a person chooses now when null
     * @return array<string, mixed> the master of these cells with its prompt and inputs
     *
     * @throws RuntimeException when a cell is not a planned reference, is already answered or held, or the cells mix input regimes
     */
    public function sheetOf(string $projectId, array $preview, array $cellIds, array $allowed, ?string $ignore = null, ?array $layout = null): array
    {
        $references = array_column($preview['plan']['references'], null, 'id');
        $state = $this->state($projectId, $preview, $ignore);

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

        $sheet = $layout === null ? MotoReferencePlanner::partSheet($cellIds, $this->gridChoice($projectId)) : MotoReferencePlanner::partSheetOn($cellIds, $layout);

        return $this->describe($sheet, $references, $state, $preview);
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

                if (! $sheet['renderable']) {
                    throw new RuntimeException((string) ($sheet['prompt_error'] ?? 'Master này chưa render được.'));
                }

                $entry = collect($this->models())->firstWhere('model', $model);

                if ($entry === null || ! in_array($quality, $entry['controls']['qualities'], true) || ! ImageModel::from($model)->supports(ImageQuality::from($quality))) {
                    throw new RuntimeException('Model hoặc chất lượng không hỗ trợ master Reference.');
                }

                if (! in_array($sheet['layout']['size'], $entry['controls']['sizes'], true)) {
                    throw new RuntimeException('Model '.$model.' không hỗ trợ size '.$sheet['layout']['size'].'.');
                }

                return [
                    'project_id' => $projectId, 'operation' => self::MASTER, 'task' => ModelSheetService::TASK,
                    'plan_id' => $planId, 'plan_hash' => $preview['hash'], 'sheet_id' => $sheet['id'], 'sheet_hash' => $sheet['hash'], 'attempt' => $operation,
                    'render_cells' => $sheet['cell_ids'], 'cells' => $sheet['pinned'], 'regime' => $sheet['regime'],
                    'layout' => $sheet['layout'], 'layout_sha256' => $sheet['layout_sha256'],
                    'design_stage_id' => $preview['source']['design_stage_id'], 'design_raw_sha256' => $preview['source']['design_raw_sha256'], 'model_sheet_image_id' => $preview['source']['model_sheet_image_id'],
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
        $sheet = $this->sheetOf((string) $image->project_id, $preview, (array) ($spec['render_cells'] ?? []), ['prompt_ready'], (string) $image->id, (array) ($spec['layout'] ?? []));

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
     * @throws RuntimeException when the master is not the project's, its file changed, its cells are no longer planned, or it has no pinned boxes and its lines do not give its cells for certain: what was stored before stays
     */
    public function process(string $projectId, string $artifactId): string
    {
        [$master, $image] = $this->master($projectId, $artifactId);
        $spec = (array) $image->prompt_spec_json;
        $layout = (array) $spec['layout'];

        if ($this->currentCells($projectId, $spec) === []) {
            throw new RuntimeException('Các ô của master không còn trong kế hoạch Reference hiện hành — không cắt.');
        }

        return app(SheetCropService::class)->cut($projectId, $master, $layout, (array) $spec['render_cells'], (string) $spec['plan_id'], self::ROLE,
            ['layout_sha256' => $spec['layout_sha256'], 'plan_hash' => $spec['plan_hash'], 'design_raw_sha256' => $spec['design_raw_sha256'] ?? null, 'parts' => array_column((array) ($spec['cells'] ?? []), 'part', 'id')]);
    }

    /**
     * @param  ?string  $reviewer  who decides
     *
     * @throws RuntimeException when the master is not waiting for a decision, a source changed, or a crop is missing or damaged: then nothing of the sheet is approved
     */
    public function approveSheet(string $projectId, string $artifactId, ?string $reviewer): void
    {
        DB::transaction(function () use ($projectId, $artifactId, $reviewer): void {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            [$master, $image] = $this->master($projectId, $artifactId);
            $spec = (array) $image->prompt_spec_json;
            $layout = (array) $spec['layout'];

            if ($image->status !== DesignImageStatus::RENDERED->value) {
                throw new RuntimeException('Chỉ duyệt được master đã render và chưa có quyết định.');
            }

            if (SheetCropService::cutsByLines($layout) && SheetCropService::pinnedBoxes($master) === null) {
                throw new RuntimeException('Master chưa được cắt theo đường viền — bấm Xử lý lại từ master.');
            }

            $current = $this->currentCells($projectId, $spec);
            $pinned = array_column((array) ($spec['cells'] ?? []), null, 'id');
            $crops = VideoArtifact::query()->where('project_id', $projectId)->where('role', self::ROLE)->whereIn('id', array_column(app(DesignImageStore::class)->sheetCrops($projectId, $master, self::ROLE), 'id'))->get()
                ->keyBy(static fn (VideoArtifact $crop): string => (string) ($crop->metadata_json['view_id'] ?? ''));
            $sizes = [];

            foreach ((array) $spec['render_cells'] as $id) {
                if (! isset($current[$id], $pinned[$id])) {
                    throw new RuntimeException('Một ô của sheet không còn khớp kế hoạch Reference hiện hành (nguồn hoặc dữ kiện đổi) — chưa duyệt.');
                }

                if (! $crops->has($id) || ($crops[$id]->metadata_json['parts_detail_plan_id'] ?? '') !== ($spec['plan_id'] ?? null)) {
                    throw new RuntimeException('Sheet còn thiếu ảnh cắt của part '.($pinned[$id]['part'] ?? $id).' — chưa duyệt.');
                }

                app(SheetCropService::class)->verifyCrop($crops[$id], $master, $layout, $sizes);
            }

            $sheet = VideoReviewDecision::create([
                'project_id' => $projectId, 'entity_type' => self::MASTER, 'entity_id' => $artifactId,
                'decision' => 'approved', 'revision' => 1, 'reviewer_id' => $reviewer,
                'metadata_json' => ['sha256' => $master->sha256, 'plan_id' => $spec['plan_id'], 'parts' => array_column((array) $spec['cells'], 'part', 'id')],
            ]);
            $meta = $project->metadata_json ?? [];
            $selection = (array) ($meta[self::SELECTION]['references'] ?? []);

            foreach ((array) $spec['render_cells'] as $id) {
                $crop = $crops[$id];
                $review = VideoReviewDecision::create([
                    'project_id' => $projectId, 'entity_type' => self::ROLE, 'entity_id' => (string) $crop->id,
                    'decision' => 'approved', 'revision' => 1, 'reviewer_id' => $reviewer,
                    'metadata_json' => ['reference_id' => $id, 'part_id' => $pinned[$id]['part'] ?? null, 'fingerprint' => $pinned[$id]['fingerprint'], 'sha256' => $crop->sha256, 'plan_id' => $spec['plan_id'], 'sheet_review_id' => (string) $sheet->id],
                ]);
                $selection[$id] = ['part_id' => $pinned[$id]['part'] ?? null, 'fingerprint' => $pinned[$id]['fingerprint'], 'artifact_id' => (string) $crop->id, 'review_id' => (string) $review->id, 'sha256' => $crop->sha256];
            }

            $meta[self::SELECTION] = ['references' => $selection];
            $project->forceFill(['metadata_json' => $meta])->save();
            $image->forceFill(['status' => DesignImageStatus::APPROVED->value, 'selected_artifact_id' => (string) $master->id, 'approved_at' => now(), 'approved_by' => $reviewer])->save();
        });
    }

    /**
     * @param  ?string  $reviewer  who decides
     *
     * @throws RuntimeException when the master is not waiting for a decision
     */
    public function rejectSheet(string $projectId, string $artifactId, ?string $reviewer): void
    {
        DB::transaction(function () use ($projectId, $artifactId, $reviewer): void {
            VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            [$master, $image] = $this->master($projectId, $artifactId);

            if ($image->status !== DesignImageStatus::RENDERED->value) {
                throw new RuntimeException('Chỉ từ chối được master đã render và chưa có quyết định.');
            }

            VideoReviewDecision::create([
                'project_id' => $projectId, 'entity_type' => self::MASTER, 'entity_id' => $artifactId,
                'decision' => 'rejected', 'revision' => 1, 'reviewer_id' => $reviewer,
                'metadata_json' => ['sha256' => $master->sha256, 'plan_id' => $image->prompt_spec_json['plan_id'] ?? null],
            ]);
            $image->forceFill(['status' => DesignImageStatus::REJECTED->value])->save();
        });
    }

    /**
     * @param  ?string  $reviewer  who decides
     * @return void the links to the sheet's own crops are gone, the links of other sheets and every past decision stay, and the sheet waits for a decision again, still holding its parts until it is rejected
     *
     * @throws RuntimeException when the sheet is not approved
     */
    public function revokeSheet(string $projectId, string $artifactId, ?string $reviewer): void
    {
        DB::transaction(function () use ($projectId, $artifactId, $reviewer): void {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            [$master, $image] = $this->master($projectId, $artifactId);

            if ($image->status !== DesignImageStatus::APPROVED->value) {
                throw new RuntimeException('Chỉ thu hồi được sheet đã duyệt.');
            }

            $meta = $project->metadata_json ?? [];
            $selection = (array) ($meta[self::SELECTION]['references'] ?? []);
            $ownCrops = VideoArtifact::query()->where('project_id', $projectId)->where('role', self::ROLE)->whereIn('id', array_filter(array_column($selection, 'artifact_id')))->get()
                ->filter(static fn (VideoArtifact $crop): bool => ($crop->metadata_json['master_artifact_id'] ?? null) === (string) $master->id)
                ->map(static fn (VideoArtifact $crop): string => (string) $crop->id)->all();
            $revoked = array_filter($selection, static fn (mixed $chosen): bool => is_array($chosen) && in_array($chosen['artifact_id'] ?? null, $ownCrops, true));

            VideoReviewDecision::create([
                'project_id' => $projectId, 'entity_type' => self::MASTER, 'entity_id' => $artifactId,
                'decision' => 'revoked', 'revision' => 1, 'reviewer_id' => $reviewer,
                'metadata_json' => ['sha256' => $master->sha256, 'plan_id' => $image->prompt_spec_json['plan_id'] ?? null, 'selections' => $revoked],
            ]);
            $meta[self::SELECTION] = ['references' => array_diff_key($selection, $revoked)];
            $project->forceFill(['metadata_json' => $meta])->save();
            $image->forceFill(['status' => DesignImageStatus::RENDERED->value, 'selected_artifact_id' => null, 'approved_at' => null, 'approved_by' => null])->save();
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
     * @return array{rows: int, columns: int, size: string, model: string, quality: string} the grid and render settings a person chose for the project, or the defaults; a saved value the model no longer offers falls back to a default
     */
    public function gridChoice(string $projectId): array
    {
        $saved = (array) (VideoProject::query()->find($projectId)?->metadata_json[self::DECISIONS]['layout'] ?? []);
        $default = (array) config('video.moto.reference.part_sheet.default');
        $models = $this->models();
        $model = collect($models)->firstWhere('model', $saved['model'] ?? null) ?? ($models[0] ?? null);
        $sizes = (array) ($model['controls']['sizes'] ?? []);
        $qualities = (array) ($model['controls']['qualities'] ?? []);

        return [
            'rows' => (int) ($saved['rows'] ?? $default['rows']),
            'columns' => (int) ($saved['columns'] ?? $default['columns']),
            'size' => in_array($saved['size'] ?? null, $sizes, true) ? (string) $saved['size'] : (in_array($default['size'], $sizes, true) ? (string) $default['size'] : (string) ($sizes[0] ?? $default['size'])),
            'model' => (string) ($model['model'] ?? ''),
            'quality' => in_array($saved['quality'] ?? null, $qualities, true) ? (string) $saved['quality'] : (string) $default['quality'],
        ];
    }

    /**
     * @throws RuntimeException when the grid is out of range or the model does not offer the size or quality
     */
    public function saveGridChoice(string $projectId, int $rows, int $columns, string $size, string $model, string $quality): void
    {
        $grid = (array) config('video.moto.reference.part_sheet.grid');

        if ($rows < $grid['min'] || $rows > $grid['max'] || $columns < $grid['min'] || $columns > $grid['max']) {
            throw new RuntimeException('Hàng và cột phải từ '.$grid['min'].' đến '.$grid['max'].'.');
        }

        $entry = collect($this->models())->firstWhere('model', $model);

        if ($entry === null || ! in_array($size, $entry['controls']['sizes'], true)) {
            throw new RuntimeException('Model '.$model.' không hỗ trợ size '.$size.'.');
        }

        if (! in_array($quality, $entry['controls']['qualities'], true) || ! ImageModel::from($model)->supports(ImageQuality::from($quality))) {
            throw new RuntimeException('Model '.$model.' không hỗ trợ chất lượng '.$quality.'.');
        }

        DB::transaction(function () use ($projectId, $rows, $columns, $size, $model, $quality): void {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $meta = $project->metadata_json ?? [];
            $meta[self::DECISIONS]['layout'] = ['rows' => $rows, 'columns' => $columns, 'size' => $size, 'model' => $model, 'quality' => $quality];
            $project->forceFill(['metadata_json' => $meta])->save();
        });
    }

    /**
     * @param  list<array<string, mixed>>  $shots  the shots of a derived shot plan
     * @param  list<string>  $finished  the finished build states of that plan
     * @return ?array<string, array{candidate: ?array{source: string, artifact_id: ?string}, problem: ?string}> for every design and identity need by its id, the image that answers it now or why none does: an approved crop of the part answers only a need that shows the part finished and installed, every other need of the part waits for the keyframe step; null when no model sheet is approved or the plan cannot be built
     */
    public function bindings(string $projectId, array $shots, array $finished): ?array
    {
        if (! $this->supports($projectId)) {
            return null;
        }

        try {
            $preview = $this->memo('bindings', $projectId, $shots, function () use ($projectId, $shots, $finished): array {
                $sheet = $this->sheetSources($projectId);

                return ['plan' => MotoReferencePlanner::partPlan($shots, $sheet['design'], $finished, $sheet['views'], (array) config('video.moto.reference'), $sheet['manifest']), 'views' => $sheet['views']];
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
                'reference' => match (true) {
                    ($state[$binding['source']]['crop'] ?? null) === null => ['candidate' => null, 'problem' => 'chờ ảnh Reference '.$references[$binding['source']]['key'].' ('.(self::STATUS_LABELS[$state[$binding['source']]['status']][1] ?? $state[$binding['source']]['status']).')'],
                    $binding['rule'] === 'part_finished' => ['candidate' => ['source' => (string) $references[$binding['source']]['key'], 'artifact_id' => (string) $state[$binding['source']]['crop']['id']], 'problem' => null],
                    default => ['candidate' => null, 'problem' => 'đã có ảnh part '.$references[$binding['source']]['part'].'; chờ bước keyframe xử lý trạng thái của shot'],
                },
                default => ['candidate' => null, 'problem' => match ($binding['rule']) {
                    'identity_waiting_keyframe' => 'nhận diện ở trạng thái này chờ bước keyframe',
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
        $images = VideoDesignImage::query()->where('project_id', $projectId)->where('image_type', self::MASTER)->with('artifacts')->latest()->get();
        $revoked = VideoReviewDecision::query()->where('project_id', $projectId)->where('entity_type', self::MASTER)->where('decision', 'revoked')->pluck('entity_id')->flip();
        $masters = [];

        foreach ($images as $image) {
            $artifacts = [];

            foreach ($image->artifacts as $artifact) {
                $crops = app(DesignImageStore::class)->sheetCrops($projectId, $artifact, self::ROLE);
                $processing = $artifact->metadata_json['parts_detail'] ?? null;
                $cells = (array) ($image->prompt_spec_json['render_cells'] ?? []);
                $artifacts[] = [
                    'artifact' => $artifact,
                    'crops' => $crops,
                    'processing' => $processing,
                    'approvable' => $image->status === DesignImageStatus::RENDERED->value && $cells !== [] && array_diff($cells, array_column($crops, 'view_id')) === []
                        && array_filter($cells, static fn (string $id): bool => ($state[$id]['status'] ?? null) !== 'awaiting_decision') === []
                        && (! SheetCropService::cutsByLines((array) ($image->prompt_spec_json['layout'] ?? [])) || SheetCropService::pinnedBoxes($artifact) !== null),
                    'revoked' => $image->status === DesignImageStatus::RENDERED->value && $revoked->has((string) $artifact->id),
                ];
            }

            $masters[] = ['image' => $image, 'artifacts' => $artifacts, 'task' => ImageRenderQueue::taskOf($image)];
        }

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
                $binding['type'] === 'model_sheet', $status === 'approved' && $binding['rule'] === 'part_finished' => 'done',
                $status === 'approved', $binding['rule'] === 'identity_waiting_keyframe', in_array($status, ['rendering', 'awaiting_crop', 'awaiting_decision'], true) => 'pending',
                default => 'missing',
            };
            $counts[$bucket]++;
        }

        $choice = $this->gridChoice($projectId);
        $total = count($references);
        $approved = count(array_filter($state, static fn (array $entry): bool => $entry['status'] === 'approved'));
        $open = count(array_filter($state, static fn (array $entry): bool => $entry['status'] === 'prompt_ready'));
        $capacity = $choice['rows'] * $choice['columns'];
        $source = $preview === null ? null : VideoDesignImage::query()->where('project_id', $projectId)->find($preview['source']['model_sheet_image_id']);
        $creator = trim((string) auth()->user()?->name);

        return [
            'error' => $error,
            'preview' => $preview,
            'stage' => $stage,
            'state' => $state,
            'references' => $references,
            'sheets' => $sheets,
            'choice' => $choice,
            'grid' => (array) config('video.moto.reference.part_sheet.grid') + ['total' => $total, 'approved' => $approved, 'unapproved' => $total - $approved, 'open' => $open, 'capacity' => $capacity, 'used' => min($open, $capacity), 'empty' => max(0, $capacity - $open), 'short' => max(0, $open - $capacity), 'waiting' => count(array_filter($state, static fn (array $entry): bool => in_array($entry['status'], ['awaiting_crop', 'awaiting_decision'], true)))],
            'source_image' => $source === null ? null : ['code' => (string) $source->image_code, 'url' => route('video-artifacts.show', $preview['source']['model_sheet_artifact_id'])],
            'next_image_code' => $creator === '' ? '' : app(DesignImageStore::class)->nextImageCode($projectId, $creator, '_parts_detail_v'),
            'masters' => $masters,
            'models' => $models,
            'prices' => $prices,
            'counts' => $counts,
            'shot_prompts' => $preview['shot_prompts'] ?? [],
            'labels' => self::STATUS_LABELS,
            'review' => app(ReferenceWriterService::class)->summary($projectId),
        ];
    }

    /** @return list<array<string, mixed>> the image models that render a master with OpenAI, each with the sizes and qualities it offers */
    public function models(): array
    {
        return array_values(array_filter(app(MediaModelRegistry::class)->forTask(ModelSheetService::TASK),
            static fn (array $m): bool => $m['provider'] === 'openai'));
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
                'planner' => MotoReferencePlanner::PART_VERSION,
                'prompt' => MotoReferencePlanner::PART_PROMPT_VERSION,
                'schema' => MotoReferencePlanner::PART_SCHEMA,
                'settings' => CanonicalJson::hash(array_intersect_key((array) config('video.moto.reference'), array_flip(['part_sheet', 'prompt']))),
            ],
        ];
    }

    /** @param  array<string, mixed>  $preview */
    private function store(string $projectId, array $preview, string $expectedHash): VideoPlanningStage
    {
        $this->same($expectedHash, $preview['hash']);
        $store = app(PlanningStageStore::class);
        [$stage, $token, $reason] = $store->claimProjectStage($projectId, PlanningStageName::REFERENCE_PROMPT,
            ['execution' => MotoReferencePlanner::PART_VERSION, 'fingerprint' => $preview['hash']], false);

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
     * @param  array{id: string, regime: string, medium: string, cell_ids: list<string>, shortage: int, layout: array<string, mixed>, layout_sha256: string}  $sheet
     * @param  array<string, array<string, mixed>>  $references
     * @param  array<string, array<string, mixed>>  $state
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed> the master with each cell and where it sits, the size of its cells, the prompt PHP puts together from the design, whether it can render and the hash of its layout, cells, prompt and sources; a grid too small for the cells has no prompt and says how many cells it lacks
     */
    private function describe(array $sheet, array $references, array $state, array $preview): array
    {
        $positions = array_column((new ModelSheetMaster($sheet['layout']))->cells(), null, 'id');
        $placed = array_intersect_key($positions, array_flip($sheet['cell_ids']));
        $pinned = array_map(static fn (string $id): array => ['id' => $id, 'part' => $references[$id]['part'], 'fingerprint' => $references[$id]['fingerprint']], $sheet['cell_ids']);
        $ready = $sheet['shortage'] === 0;
        $error = $ready ? null : 'Lưới '.$sheet['layout']['rows'].' × '.$sheet['layout']['columns'].' có '.($sheet['layout']['rows'] * $sheet['layout']['columns']).' ô, thiếu '.$sheet['shortage'].' ô cho '.count($sheet['cell_ids']).' part — tăng Hàng hoặc Cột.';
        $prompt = null;

        if ($ready) {
            try {
                $prompt = MotoReferencePlanner::partPrompt($sheet, $references, (array) config('video.moto.reference'));
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
                $ready = false;
            }
        }

        foreach ($sheet['cell_ids'] as $id) {
            $ready = $ready && $state[$id]['status'] === 'prompt_ready';
        }

        return $sheet + [
            'cells' => array_map(static fn (string $id): array => ['reference' => $references[$id], 'state' => $state[$id], 'position' => $positions[$id] ?? null], $sheet['cell_ids']),
            'cell_size' => ['width' => MotoReferencePlanner::spread(array_column($placed, 'width')), 'height' => MotoReferencePlanner::spread(array_column($placed, 'height'))],
            'review_cells' => [],
            'confirm_cells' => [],
            'renderable' => $ready,
            'prompt' => $prompt,
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
     * @return array<string, array{status: string, master: string}> the planned cells a master holds: on its way, waiting to be cut, or waiting for the decision on a sheet that has none now, whether it never had one or its approval was taken back
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
            $planned = array_keys(array_filter($cells, static fn (mixed $fingerprint, string $id): bool => ($references[$id]['fingerprint'] ?? null) === $fingerprint, ARRAY_FILTER_USE_BOTH));

            if ($planned === []) {
                continue;
            }

            if (in_array($image->status, self::IN_FLIGHT, true)) {
                foreach ($planned as $id) {
                    $held[$id] = ['status' => 'rendering', 'master' => (string) $image->id];
                }

                continue;
            }

            foreach ($image->artifacts as $master) {
                $crops = array_column(app(DesignImageStore::class)->sheetCrops($projectId, $master, self::ROLE), 'id', 'view_id');

                foreach ($planned as $id) {
                    $held[$id] = ['status' => isset($crops[$id]) ? 'awaiting_decision' : 'awaiting_crop', 'master' => (string) $image->id];
                }
            }
        }

        return $held;
    }

    /**
     * @param  array<string, array{artifact_id?: string, review_id?: string, sha256?: string}>  $selection  what is chosen for each reference
     * @return array<string, array<string, mixed>> by reference, the approved crop while it is current, whole and still approved; the rows are read in one go and each master file once
     */
    private function validSelections(string $projectId, array $selection): array
    {
        if ($selection === []) {
            return [];
        }

        $crops = VideoArtifact::query()->where('project_id', $projectId)->where('role', self::ROLE)->whereIn('id', array_filter(array_column($selection, 'artifact_id')))->get()->keyBy('id');
        $reviews = VideoReviewDecision::query()->where('project_id', $projectId)->where('entity_type', self::ROLE)->where('decision', 'approved')->whereIn('id', array_filter(array_column($selection, 'review_id')))->get()->keyBy('id');
        $masters = VideoArtifact::query()->where('project_id', $projectId)->whereIn('id', $crops->map(static fn (VideoArtifact $crop): string => (string) ($crop->metadata_json['master_artifact_id'] ?? ''))->filter()->unique()->values())->get()->keyBy('id');
        $images = VideoDesignImage::query()->where('project_id', $projectId)->where('image_type', self::MASTER)->whereIn('id', $masters->pluck('design_image_id')->filter()->unique()->values())->get()->keyBy('id');
        $sizes = [];
        $valid = [];

        foreach ($selection as $id => $chosen) {
            $crop = $crops->get($chosen['artifact_id'] ?? null);
            $review = $reviews->get($chosen['review_id'] ?? null);

            if ($crop === null || $review === null || isset($crop->metadata_json['superseded_at']) || (string) $review->entity_id !== (string) $crop->id
                || ($review->metadata_json['sha256'] ?? null) !== $crop->sha256 || ($chosen['sha256'] ?? null) !== $crop->sha256) {
                continue;
            }

            $master = $masters->get($crop->metadata_json['master_artifact_id'] ?? null);
            $image = $master === null ? null : $images->get($master->design_image_id);

            if ($image === null) {
                continue;
            }

            try {
                app(SheetCropService::class)->verifyCrop($crop, $master, (array) $image->prompt_spec_json['layout'], $sizes);
            } catch (RuntimeException) {
                continue;
            }

            $valid[$id] = ['id' => (string) $crop->id, 'url' => route('video-artifacts.show', $crop->id), 'sha256' => (string) $crop->sha256, 'master_image_id' => (string) $image->id];
        }

        return $valid;
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

    private function same(string $expected, string $actual): void
    {
        if ($expected === '' || ! hash_equals($expected, $actual)) {
            throw new RuntimeException('Nguồn hoặc nội dung Reference đã đổi. Tải lại trang rồi làm lại từ kế hoạch hiện hành.');
        }
    }
}
