<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoReviewDecision;
use App\Video\Media\OpenAiImagePricing;
use App\Video\ModelSheet\ModelSheetMaster;
use App\Video\Moto\MotoCharacterPlanner;
use App\Video\Queue\VideoQueues;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class MotoCharacterReferenceService implements QueuedImageService
{
    public const MASTER = 'character_master';

    public const ROLE = 'character_crop';

    public const STEP = 'character';

    public const SELECTION = 'moto_character_selection';

    public const CHOICES = 'moto_character_choices';

    public const SPEC_VERSION = 'moto-character-master-v1';

    /** @var array<string, array{0: string, 1: string}> the tone and label of every state of a character cell */
    public const STATUS_LABELS = [
        'approved' => ['ok', 'ĐÃ DUYỆT'],
        'rendering' => ['blue', 'ĐANG RENDER'],
        'awaiting_crop' => ['amber', 'CHỜ CẮT'],
        'awaiting_decision' => ['amber', 'CHỜ DUYỆT'],
        'open' => ['dg', 'CẦN TẠO'],
    ];

    public function __construct(
        private readonly ShotSourceService $shots,
        private readonly DesignImageStore $store,
        private readonly OpenAiImagePricing $pricing,
        private readonly SheetCropService $crops,
    ) {}

    /**
     * @return array{approved: array<string, mixed>, plan: array<string, mixed>, characters: array<string, string>} the approved shot plan, the character cells its stored shots need and the descriptions the plan pinned
     *
     * @throws RuntimeException when no shot plan is approved or it no longer matches its approval
     */
    public function source(string $projectId): array
    {
        $approved = $this->shots->approvedSource($projectId);
        $stage = VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)->findOrFail($approved['stage_id']);
        $metadata = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null);
        $characters = (array) ($metadata['context']['characters'] ?? []);

        return [
            'approved' => $approved,
            'plan' => MotoCharacterPlanner::plan(array_values((array) ($stage->output_json['derived']['shots'] ?? [])), $characters, (array) config('video.moto.reference.character')),
            'characters' => $characters,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan  MotoCharacterPlanner::plan()
     * @return array<string, array<string, mixed>> by cell, the image a keyframe may send now: the crop of a master, or the whole image of a cell rendered alone, while its master is still approved under its decision and its file, hash and place are whole
     */
    public function approvedCells(string $projectId, array $plan): array
    {
        $selection = (array) (VideoProject::query()->whereKey($projectId)->value('metadata_json')[self::SELECTION]['cells'] ?? []);
        $cells = array_column((array) $plan['cells'], null, 'id');
        $valid = [];
        $sizes = [];

        foreach ($selection as $id => $chosen) {
            if (! isset($cells[$id]) || ! is_array($chosen) || ($chosen['fingerprint'] ?? null) !== $cells[$id]['fingerprint']) {
                continue;
            }

            try {
                $valid[(string) $id] = $this->entry($projectId, (string) $id, $chosen, $sizes);
            } catch (RuntimeException) {
                continue;
            }
        }

        return $valid;
    }

    /** @return array<string, mixed> everything the characters block of the Reference screen shows; reading it writes nothing */
    public function panel(string $projectId): array
    {
        $empty = ['error' => null, 'problems' => [], 'rows' => [], 'masters' => [], 'total' => 0, 'approved' => 0, 'kinds' => [], 'labels' => self::STATUS_LABELS, 'models' => $this->models()];

        try {
            $source = $this->source($projectId);
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage()] + $empty;
        }

        $plan = $source['plan'];
        $approved = $this->approvedCells($projectId, $plan);
        $state = $this->state($projectId, $plan, $approved);
        $rows = [];

        foreach (array_values(array_unique(array_column((array) $plan['cells'], 'character'))) as $character) {
            $rows[] = $this->row($projectId, (string) $character, $source, $state) + ['description' => (string) ($source['characters'][$character] ?? '')];
        }

        $kinds = [];

        foreach ((array) $plan['cells'] as $cell) {
            $kinds[$cell['kind']]['total'] = ($kinds[$cell['kind']]['total'] ?? 0) + 1;
            $kinds[$cell['kind']]['approved'] = ($kinds[$cell['kind']]['approved'] ?? 0) + (isset($approved[$cell['id']]) ? 1 : 0);
        }

        return [
            'error' => null,
            'problems' => array_values((array) $plan['problems']),
            'rows' => $rows,
            'masters' => $this->masters($projectId, $plan),
            'total' => count((array) $plan['cells']),
            'approved' => count($approved),
            'kinds' => $kinds,
            'labels' => self::STATUS_LABELS,
            'models' => $this->models(),
        ];
    }

    /** @throws RuntimeException when the character is not one the shots need, or the model does not offer the size or the quality */
    public function saveChoice(string $projectId, string $character, string $model, string $quality, string $size): void
    {
        $entry = collect($this->models())->firstWhere('model', $model);

        if ($entry === null || ! in_array($size, $entry['controls']['sizes'], true) || ! in_array($quality, $this->qualities($entry), true)) {
            throw new RuntimeException('Model, chất lượng hoặc size không hợp lệ.');
        }

        DB::transaction(function () use ($projectId, $character, $model, $quality, $size): void {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

            if (! in_array($character, array_column((array) $this->source($projectId)['plan']['cells'], 'character'), true)) {
                throw new RuntimeException('Nhân vật này không có trong nhu cầu của shot.');
            }

            $meta = $project->metadata_json ?? [];
            $meta[self::CHOICES][$character] = ['model' => $model, 'quality' => $quality, 'size' => $size];
            $project->forceFill(['metadata_json' => $meta])->save();
        });
    }

    /**
     * @param  ?array{id: string, name: string}  $requester
     * @return array{0: ?VideoDesignImage, 1: string} reason queued, rendered, failed, processing_failed|…, already_exists, or why nothing was sent
     */
    public function render(string $projectId, string $character, string $sheetHash, string $operation, string $creator, ?array $requester = null): array
    {
        if (! Str::isUuid($operation)) {
            return [null, 'Mã lượt render không hợp lệ.'];
        }

        $create = function () use ($projectId, $character, $sheetHash, $operation, $creator): array {
            VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $existing = VideoDesignImage::query()->where('project_id', $projectId)->where('image_type', self::MASTER)->where('prompt_spec_json->attempt', $operation)->first();

            if ($existing !== null) {
                return hash_equals((string) ($existing->prompt_spec_json['sheet_hash'] ?? ''), $sheetHash)
                    ? [$existing, 'already_exists']
                    : [null, 'Mã thao tác đã dùng cho một lượt render khác — tải lại trang.'];
            }

            $source = $this->source($projectId);
            $state = $this->state($projectId, $source['plan'], $this->approvedCells($projectId, $source['plan']));
            $sheet = $this->row($projectId, $character, $source, $state)['sheet'];

            if ($sheet === null) {
                return [null, 'Nhân vật này không còn ô nào cần tạo.'];
            }

            if ($sheet['prompt_error'] !== null) {
                return [null, (string) $sheet['prompt_error']];
            }

            if (! hash_equals($sheet['hash'], $sheetHash)) {
                return [null, 'Nguồn hoặc cấu hình đã đổi so với lúc xem — tải lại trang, chưa render.'];
            }

            [$image, $reason] = $this->store->createCharacterMaster($projectId, $creator, [
                'project_id' => $projectId,
                'operation' => self::MASTER,
                'spec_version' => self::SPEC_VERSION,
                'character' => $character,
                'sheet_id' => $sheet['id'],
                'sheet_hash' => $sheet['hash'],
                'attempt' => $operation,
                'source_token' => $source['approved']['token'],
                'plan_version' => $source['plan']['version'],
                'prompt_version' => $source['plan']['prompt_version'],
                'render_cells' => $sheet['cell_ids'],
                'cells' => $sheet['pinned'],
                'layout' => $sheet['layout'],
                'layout_sha256' => $sheet['layout_sha256'],
                'prompt' => $sheet['prompt'],
                'prompt_sha256' => CanonicalJson::hashString((string) $sheet['prompt']),
                'sources' => [],
                'provider' => 'openai',
                'model' => $sheet['choice']['model'],
                'quality' => $sheet['choice']['quality'],
                'size' => $sheet['choice']['size'],
                'variations' => 1,
                'pricing' => $sheet['pricing'],
            ]);

            return $image === null ? [null, 'Không tạo được ô ảnh ('.$reason.').'] : [$image, $reason];
        };

        try {
            if (VideoQueues::enabled()) {
                return app(ImageRenderQueue::class)->createQueued(
                    self::STEP,
                    $create,
                    static fn (VideoDesignImage $image, string $reason): ?array => $reason === 'already_exists' && $image->status !== DesignImageStatus::CANDIDATE->value ? [$image, 'already_exists'] : null,
                    $requester,
                );
            }

            [$image, $reason] = DB::transaction($create);
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }

        if ($image === null || $reason === 'already_exists') {
            return [$image, $reason];
        }

        $problem = $this->checkQueued($image);

        if ($problem !== null) {
            app(DesignImageQueue::class)->refuse((string) $image->id, $problem);

            return [null, $problem];
        }

        return array_slice($this->sendQueued($image), 0, 2);
    }

    public function checkQueued(VideoDesignImage $image): ?string
    {
        $spec = (array) $image->prompt_spec_json;

        try {
            $source = $this->source((string) $image->project_id);
        } catch (RuntimeException $e) {
            return $e->getMessage().' — không gửi render.';
        }

        if (($spec['source_token'] ?? null) != $source['approved']['token']) {
            return 'Kế hoạch shot đã đổi so với lúc xác nhận — không gửi render.';
        }

        $cells = array_column((array) $source['plan']['cells'], null, 'id');

        foreach ((array) ($spec['cells'] ?? []) as $pinned) {
            if (($cells[$pinned['id']]['fingerprint'] ?? null) !== $pinned['fingerprint']) {
                return 'Ảnh '.$pinned['code'].' không còn khớp nhu cầu nhân vật — không gửi render.';
            }
        }

        $sheet = ['cell_ids' => (array) ($spec['render_cells'] ?? []), 'single' => count((array) ($spec['render_cells'] ?? [])) === 1, 'layout' => (array) ($spec['layout'] ?? [])];

        if (! hash_equals((string) ($spec['prompt'] ?? ''), MotoCharacterPlanner::prompt($sheet, $cells, (array) config('video.moto.reference.character')))) {
            return 'Prompt nhân vật không còn là prompt đã xác nhận — không gửi render.';
        }

        return null;
    }

    public function sendQueued(VideoDesignImage $image): array
    {
        [$done, $reason, $refusal] = app(DesignImageDirectRenderer::class)->renderNow((string) $image->id);

        if ($reason !== 'rendered' || $done === null || ! SheetCropService::cutsByLines((array) ($done->prompt_spec_json['layout'] ?? []))) {
            return [$done, $reason, $refusal];
        }

        try {
            $this->process((string) $done->project_id, (string) $done->artifacts()->orderBy('created_at')->orderBy('id')->first()?->id);
        } catch (RuntimeException $e) {
            return [$done, 'processing_failed|'.$e->getMessage(), null];
        }

        return [$done, 'rendered', null];
    }

    /**
     * @return string processed, already_processed, whole for an image with no grid
     *
     * @throws RuntimeException when the master is not a character master of the project, its file changed, its cells are no longer needed, or it has no pinned boxes and its lines do not give its cells for certain: what was stored before stays
     */
    public function process(string $projectId, string $artifactId): string
    {
        [$master, $image] = $this->master($projectId, $artifactId);
        $spec = (array) $image->prompt_spec_json;
        $layout = (array) $spec['layout'];

        if (! SheetCropService::cutsByLines($layout)) {
            return 'whole';
        }

        if ($this->stalePinned($projectId, $spec) !== null) {
            throw new RuntimeException('Các ô của master không còn trong nhu cầu nhân vật hiện hành — không cắt.');
        }

        return $this->crops->cut($projectId, $master, $layout, (array) $spec['render_cells'], (string) $spec['sheet_id'], self::ROLE, ['layout_sha256' => $spec['layout_sha256'], 'sheet_hash' => $spec['sheet_hash'], 'cells' => array_column((array) $spec['cells'], 'code', 'id')]);
    }

    /**
     * @param  ?string  $reviewer  who decides
     * @return void the whole master is approved at once: the decision and the choice of every cell are stored together
     *
     * @throws RuntimeException when the master is not waiting for a decision, a cell is no longer needed, or a file is missing or damaged: then nothing of it is approved
     */
    public function approve(string $projectId, string $artifactId, ?string $reviewer): void
    {
        DB::transaction(function () use ($projectId, $artifactId, $reviewer): void {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            [$master, $image] = $this->master($projectId, $artifactId);
            $spec = (array) $image->prompt_spec_json;
            $layout = (array) $spec['layout'];

            if ($image->status !== DesignImageStatus::RENDERED->value) {
                throw new RuntimeException('Chỉ duyệt được ảnh đã render và chưa có quyết định.');
            }

            $stale = $this->stalePinned($projectId, $spec);

            if ($stale !== null) {
                throw new RuntimeException($stale.' — chưa duyệt.');
            }

            $cuts = SheetCropService::cutsByLines($layout);
            $crops = [];

            if ($cuts) {
                if (SheetCropService::pinnedBoxes($master) === null) {
                    throw new RuntimeException('Master chưa được cắt theo đường viền — bấm Xử lý lại từ master.');
                }

                $crops = VideoArtifact::query()->where('project_id', $projectId)->where('role', self::ROLE)->whereIn('id', array_column($this->store->sheetCrops($projectId, $master, self::ROLE), 'id'))->get()
                    ->keyBy(static fn (VideoArtifact $crop): string => (string) ($crop->metadata_json['view_id'] ?? ''))->all();
                $sizes = [];

                foreach ((array) $spec['render_cells'] as $id) {
                    if (! isset($crops[$id])) {
                        throw new RuntimeException('Master còn thiếu ảnh cắt của một ô — chưa duyệt.');
                    }

                    $this->crops->verifyCrop($crops[$id], $master, $layout, $sizes);
                }
            } else {
                $read = @getimagesizefromstring($this->crops->bytes($master)) ?: [];

                if (($read[0] ?? 0).'x'.($read[1] ?? 0) !== (string) $spec['size']) {
                    throw new RuntimeException('Ảnh sai kích thước hoặc không đọc được — chưa duyệt.');
                }
            }

            $decision = VideoReviewDecision::create([
                'project_id' => $projectId,
                'entity_type' => self::MASTER,
                'entity_id' => (string) $master->id,
                'decision' => 'approved',
                'revision' => $this->nextRevision((string) $master->id),
                'reviewer_id' => $reviewer,
                'metadata_json' => ['sha256' => $master->sha256, 'character' => $spec['character'], 'sheet_hash' => $spec['sheet_hash'], 'cells' => array_column((array) $spec['cells'], 'code', 'id')],
            ]);
            $meta = $project->metadata_json ?? [];
            $pinned = array_column((array) $spec['cells'], null, 'id');

            foreach ((array) $spec['render_cells'] as $id) {
                $artifact = $cuts ? $crops[$id] : $master;
                $meta[self::SELECTION]['cells'][$id] = [
                    'fingerprint' => $pinned[$id]['fingerprint'],
                    'code' => $pinned[$id]['code'],
                    'artifact_id' => (string) $artifact->id,
                    'sha256' => (string) $artifact->sha256,
                    'master_artifact_id' => (string) $master->id,
                    'master_sha256' => (string) $master->sha256,
                    'master_image_id' => (string) $image->id,
                    'review_id' => (string) $decision->id,
                ];
            }

            $project->forceFill(['metadata_json' => $meta])->save();
            $image->forceFill(['status' => DesignImageStatus::APPROVED->value, 'selected_artifact_id' => (string) $master->id, 'approved_at' => now(), 'approved_by' => $reviewer])->save();
        });
    }

    /**
     * @param  ?string  $reviewer  who decides
     *
     * @throws RuntimeException when the master is not waiting for a decision
     */
    public function reject(string $projectId, string $artifactId, ?string $reviewer): void
    {
        DB::transaction(function () use ($projectId, $artifactId, $reviewer): void {
            VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            [$master, $image] = $this->master($projectId, $artifactId);

            if ($image->status !== DesignImageStatus::RENDERED->value) {
                throw new RuntimeException('Chỉ từ chối được ảnh đã render và chưa có quyết định.');
            }

            VideoReviewDecision::create([
                'project_id' => $projectId,
                'entity_type' => self::MASTER,
                'entity_id' => (string) $master->id,
                'decision' => 'rejected',
                'revision' => $this->nextRevision((string) $master->id),
                'reviewer_id' => $reviewer,
                'metadata_json' => ['sha256' => $master->sha256, 'character' => $image->prompt_spec_json['character'] ?? null],
            ]);
            $image->forceFill(['status' => DesignImageStatus::REJECTED->value])->save();
        });
    }

    /**
     * @param  ?string  $reviewer  who decides
     * @return void the choices made from this master are gone, every other choice and every past decision stay, and it waits for a decision again
     *
     * @throws RuntimeException when the master is not approved
     */
    public function revoke(string $projectId, string $artifactId, ?string $reviewer): void
    {
        DB::transaction(function () use ($projectId, $artifactId, $reviewer): void {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            [$master, $image] = $this->master($projectId, $artifactId);

            if ($image->status !== DesignImageStatus::APPROVED->value) {
                throw new RuntimeException('Chỉ thu hồi được ảnh đã duyệt.');
            }

            $meta = $project->metadata_json ?? [];
            $selection = (array) ($meta[self::SELECTION]['cells'] ?? []);
            $revoked = array_filter($selection, static fn (mixed $chosen): bool => is_array($chosen) && ($chosen['master_artifact_id'] ?? null) === (string) $master->id);
            VideoReviewDecision::create([
                'project_id' => $projectId,
                'entity_type' => self::MASTER,
                'entity_id' => (string) $master->id,
                'decision' => 'revoked',
                'revision' => $this->nextRevision((string) $master->id),
                'reviewer_id' => $reviewer,
                'metadata_json' => ['sha256' => $master->sha256, 'character' => $image->prompt_spec_json['character'] ?? null, 'selections' => $revoked],
            ]);
            $meta[self::SELECTION]['cells'] = array_diff_key($selection, $revoked);
            $project->forceFill(['metadata_json' => $meta])->save();
            $image->forceFill(['status' => DesignImageStatus::RENDERED->value, 'selected_artifact_id' => null, 'approved_at' => null, 'approved_by' => null])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $chosen  a line of the selection
     * @param  array<string, mixed>  $sizes  the size of each master read so far, by artifact id
     * @return array<string, mixed> the image of the cell with its master and decision
     *
     * @throws RuntimeException when the master is no longer approved under this decision, or the file, hash or place of the image is not whole
     */
    private function entry(string $projectId, string $id, array $chosen, array &$sizes): array
    {
        [$master, $image] = $this->master($projectId, (string) ($chosen['master_artifact_id'] ?? ''));
        $decision = VideoReviewDecision::query()->whereKey($chosen['review_id'] ?? null)->where('project_id', $projectId)->where('entity_type', self::MASTER)->where('entity_id', (string) $master->id)->where('decision', 'approved')->first();

        if ($image->status !== DesignImageStatus::APPROVED->value || (string) $image->selected_artifact_id !== (string) $master->id || $decision === null
            || ($decision->metadata_json['sha256'] ?? null) !== (string) $master->sha256 || ($chosen['master_sha256'] ?? null) !== (string) $master->sha256) {
            throw new RuntimeException('Master không còn được duyệt.');
        }

        $whole = (string) ($chosen['artifact_id'] ?? '') === (string) $master->id;
        $artifact = $whole ? $master : VideoArtifact::query()->where('project_id', $projectId)->where('role', self::ROLE)->find($chosen['artifact_id'] ?? null);

        if ($artifact === null || (string) $artifact->sha256 !== (string) ($chosen['sha256'] ?? '') || isset($artifact->metadata_json['superseded_at'])) {
            throw new RuntimeException('Ảnh của ô không còn.');
        }

        if ($whole) {
            $this->crops->bytes($master);
        } else {
            if (($artifact->metadata_json['view_id'] ?? null) !== $id || ($artifact->metadata_json['master_artifact_id'] ?? null) !== (string) $master->id) {
                throw new RuntimeException('Crop không thuộc ô hoặc master này.');
            }

            $this->crops->verifyCrop($artifact, $master, (array) $image->prompt_spec_json['layout'], $sizes);
        }

        return [
            'artifact_id' => (string) $artifact->id,
            'sha256' => (string) $artifact->sha256,
            'candidate_id' => $whole ? (string) $image->id : null,
            'image_id' => (string) $image->id,
            'code' => (string) ($chosen['code'] ?? ''),
            'fingerprint' => (string) $chosen['fingerprint'],
            'master_artifact_id' => (string) $master->id,
            'master_sha256' => (string) $master->sha256,
            'master_code' => (string) $image->image_code,
            'decision_id' => (string) $decision->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, array<string, mixed>>  $approved  approvedCells()
     * @return array<string, string> where each cell stands now: approved, held by a master on its way or waiting for a decision, or still to make
     */
    private function state(string $projectId, array $plan, array $approved): array
    {
        $cells = array_column((array) $plan['cells'], null, 'id');
        $state = array_map(static fn (): string => 'open', $cells);
        $images = VideoDesignImage::query()->where('project_id', $projectId)->where('image_type', self::MASTER)
            ->whereIn('status', [DesignImageStatus::CANDIDATE->value, DesignImageStatus::CLAIMED->value, DesignImageStatus::RENDERING->value, DesignImageStatus::RENDERED->value])->with('artifacts')->get();

        foreach ($images as $image) {
            $status = null;

            if ($image->status !== DesignImageStatus::RENDERED->value) {
                $status = ImageRenderQueue::taskOf($image) === null ? null : 'rendering';
            }

            foreach ((array) ($image->prompt_spec_json['cells'] ?? []) as $pinned) {
                $id = (string) $pinned['id'];

                if (($cells[$id]['fingerprint'] ?? null) !== $pinned['fingerprint']) {
                    continue;
                }

                if ($status !== null) {
                    $state[$id] = $status;

                    continue;
                }

                if ($image->status === DesignImageStatus::RENDERED->value) {
                    $artifact = $image->artifacts->first();
                    $cuts = SheetCropService::cutsByLines((array) ($image->prompt_spec_json['layout'] ?? []));
                    $state[$id] = ! $cuts || ($artifact !== null && in_array($id, array_column($this->store->sheetCrops($projectId, $artifact, self::ROLE), 'view_id'), true)) ? 'awaiting_decision' : 'awaiting_crop';
                }
            }
        }

        foreach (array_keys($approved) as $id) {
            $state[$id] = 'approved';
        }

        return $state;
    }

    /**
     * @param  array{approved: array<string, mixed>, plan: array<string, mixed>}  $source
     * @param  array<string, string>  $state
     * @return array{character: string, cells: list<array<string, mixed>>, sheet: ?array<string, mixed>} the cells of the character with where each stands, and the master still to make from those nothing answers yet
     */
    private function row(string $projectId, string $character, array $source, array $state): array
    {
        $cells = array_values(array_filter((array) $source['plan']['cells'], static fn (array $cell): bool => $cell['character'] === $character));
        $withState = array_map(static fn (array $cell): array => $cell + ['status' => $state[$cell['id']] ?? 'open'], $cells);
        $open = array_values(array_filter($withState, static fn (array $cell): bool => $cell['status'] === 'open'));

        return ['character' => $character, 'cells' => $withState, 'sheet' => $open === [] ? null : $this->describe($projectId, $character, $open, $cells, $source)];
    }

    /**
     * @param  list<array<string, mixed>>  $open  the cells to render
     * @param  list<array<string, mixed>>  $cells  every cell of the character
     * @param  array{approved: array<string, mixed>, plan: array<string, mixed>}  $source
     * @return array<string, mixed> the master of the cells, the size of its cells, the prompt PHP puts together, the settings and the hash of everything a render would pin
     */
    private function describe(string $projectId, string $character, array $open, array $cells, array $source): array
    {
        $settings = (array) config('video.moto.reference.character');
        $choice = $this->choice($projectId, $character, count($open) === 1);
        $sheet = MotoCharacterPlanner::sheet($character, $open, $choice['size'], $settings);
        $positions = (new ModelSheetMaster($sheet['layout']))->cells();
        $prompt = null;
        $error = null;

        try {
            $prompt = MotoCharacterPlanner::prompt($sheet, array_column($open, null, 'id'), $settings);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }

        $pinned = array_map(static fn (array $cell): array => array_intersect_key($cell, array_flip(['id', 'code', 'kind', 'character', 'view', 'items', 'item', 'angle', 'label', 'fingerprint'])), $open);
        $entry = collect($this->models())->firstWhere('model', $choice['model']);

        return $sheet + [
            'cells' => $open,
            'positions' => array_column($positions, null, 'id'),
            'cell_width' => min(array_column($positions, 'width')),
            'cell_height' => min(array_column($positions, 'height')),
            'prompt' => $prompt,
            'prompt_error' => $error,
            'pinned' => $pinned,
            'choice' => $choice,
            'pricing' => (string) ($entry['pricing'] ?? ''),
            'price' => $this->pricing->unitFor($choice['model'], $choice['quality']),
            'hash' => CanonicalJson::hash([
                'token' => $source['approved']['token'],
                'sheet' => $sheet['id'],
                'pinned' => $pinned,
                'layout_sha256' => $sheet['layout_sha256'],
                'prompt' => $prompt,
                'model' => $choice['model'],
                'quality' => $choice['quality'],
                'size' => $choice['size'],
                'variations' => 1,
            ]),
        ];
    }

    /** @return array{model: string, quality: string, size: string, sizes: list<string>, qualities: list<string>} the settings saved for the character, or the defaults; a saved value the model no longer offers falls back to a default */
    private function choice(string $projectId, string $character, bool $single): array
    {
        $saved = (array) (VideoProject::query()->whereKey($projectId)->value('metadata_json')[self::CHOICES][$character] ?? []);
        $settings = (array) config('video.moto.reference.character');
        $default = (array) ($single ? $settings['default_single'] : $settings['default']);
        $models = $this->models();
        $entry = collect($models)->firstWhere('model', $saved['model'] ?? null) ?? collect($models)->firstWhere('default', true) ?? ($models[0] ?? null);

        if ($entry === null) {
            throw new RuntimeException('Không có model ảnh dùng được.');
        }

        $sizes = array_values((array) $entry['controls']['sizes']);
        $qualities = $this->qualities($entry);

        return [
            'model' => (string) $entry['model'],
            'quality' => in_array($saved['quality'] ?? null, $qualities, true) ? (string) $saved['quality'] : (in_array($default['quality'], $qualities, true) ? (string) $default['quality'] : (string) ($qualities[0] ?? '')),
            'size' => in_array($saved['size'] ?? null, $sizes, true) ? (string) $saved['size'] : (in_array($default['size'], $sizes, true) ? (string) $default['size'] : (string) ($sizes[0] ?? '')),
            'sizes' => $sizes,
            'qualities' => $qualities,
        ];
    }

    /** @return list<array<string, mixed>> the image models that render a character, the same as a Reference master */
    private function models(): array
    {
        return app(PartsDetailService::class)->models();
    }

    /**
     * @param  array<string, mixed>  $entry  a model of the registry
     * @return list<string> the qualities the model both offers and supports
     */
    private function qualities(array $entry): array
    {
        $model = ImageModel::tryFrom((string) $entry['model']);

        return array_values(array_filter((array) $entry['controls']['qualities'], static fn (string $quality): bool => $model !== null && ImageQuality::tryFrom($quality) !== null && $model->supports(ImageQuality::from($quality))));
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<array<string, mixed>> every character master, newest first, with its image and crops and what a person can do with it
     */
    private function masters(string $projectId, array $plan): array
    {
        $current = array_column((array) $plan['cells'], 'fingerprint', 'id');
        $revoked = VideoReviewDecision::query()->where('project_id', $projectId)->where('entity_type', self::MASTER)->where('decision', 'revoked')->pluck('entity_id')->flip();
        $list = [];

        foreach (VideoDesignImage::query()->where('project_id', $projectId)->where('image_type', self::MASTER)->with('artifacts')->latest()->get() as $image) {
            $spec = (array) $image->prompt_spec_json;
            $layout = (array) ($spec['layout'] ?? []);
            $cuts = SheetCropService::cutsByLines($layout);
            $fresh = collect((array) ($spec['cells'] ?? []))->every(static fn (array $cell): bool => ($current[$cell['id']] ?? null) === $cell['fingerprint']);
            $artifacts = [];

            foreach ($image->artifacts as $artifact) {
                $crops = $this->store->sheetCrops($projectId, $artifact, self::ROLE);
                $artifacts[] = [
                    'artifact' => $artifact,
                    'crops' => $crops,
                    'processing' => $artifact->metadata_json['parts_detail'] ?? null,
                    'approvable' => $image->status === DesignImageStatus::RENDERED->value && $fresh
                        && (! $cuts || (SheetCropService::pinnedBoxes($artifact) !== null && array_diff((array) ($spec['render_cells'] ?? []), array_column($crops, 'view_id')) === [])),
                    'revoked' => $image->status === DesignImageStatus::RENDERED->value && $revoked->has((string) $artifact->id),
                ];
            }

            $list[] = ['image' => $image, 'spec' => $spec, 'cuts' => $cuts, 'fresh' => $fresh, 'artifacts' => $artifacts, 'task' => ImageRenderQueue::taskOf($image)];
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return ?string why a cell of the master is no longer one the shots need as it was rendered
     */
    private function stalePinned(string $projectId, array $spec): ?string
    {
        $cells = array_column((array) $this->source($projectId)['plan']['cells'], 'fingerprint', 'id');

        foreach ((array) ($spec['cells'] ?? []) as $pinned) {
            if (($cells[$pinned['id']] ?? null) !== $pinned['fingerprint']) {
                return 'Ảnh '.$pinned['code'].' không còn khớp nhu cầu nhân vật hiện hành';
            }
        }

        return null;
    }

    private function nextRevision(string $artifactId): int
    {
        return 1 + (int) VideoReviewDecision::query()->where('entity_type', self::MASTER)->where('entity_id', $artifactId)->max('revision');
    }

    /** @return array{0: VideoArtifact, 1: VideoDesignImage} */
    private function master(string $projectId, string $artifactId): array
    {
        $master = VideoArtifact::query()->where('project_id', $projectId)->find($artifactId);
        $image = $master === null ? null : VideoDesignImage::query()->where('project_id', $projectId)->where('image_type', self::MASTER)->find($master->design_image_id);

        if ($image === null) {
            throw new RuntimeException('Ảnh không thuộc Reference nhân vật của dự án.');
        }

        return [$master, $image];
    }
}
