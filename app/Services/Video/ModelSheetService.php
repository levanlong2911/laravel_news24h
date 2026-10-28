<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\ImageVariations;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\AiPhase;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Video\Llm\JsonAnswer;
use App\Video\Media\MediaModelRegistry;
use App\Video\ModelSheet\ModelSheetFacts;
use App\Video\ModelSheet\ModelSheetMaster;
use App\Video\ModelSheet\ModelSheetPromptCompiler;
use App\Video\ModelSheet\ModelSheetQaValidator;
use App\Video\YachtDesign\YachtDesignCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Model sheet Phase 3 theo luong: thiet ke -> QA prompt -> render model sheet -> duyet anh.
 *
 * Noi dung prompt: code lap bang du kien tu ban thiet ke (ModelSheetFacts), Sonnet 5.5 QA
 * va bien tap co nguon (ModelSheetQaService), code kiem va ghep prompt cuoi. Mot lan bam
 * Render gui MOT request (variations 1) voi dung prompt da QA, tao MOT anh master 3840x2160,
 * luoi 2 hang x 4 cot MS1-MS8; may chu cat tam o 960x1080 theo toa do co dinh. Nguoi duyet
 * duyet hoac tu choi ca master; mot o sai la ca luot chua dat.
 */
final class ModelSheetService
{
    public const MASTER_OPERATION = 'model_sheet_master';

    public const TASK = 'model_sheet';

    public const REVIEW_EXECUTION = 'model-sheet-master-review-v1';

    public const CODE_SUFFIX = '_model_sheet_master_v';

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly DesignImageStore $images,
        private readonly DesignImageDirectRenderer $renderer,
        private readonly MediaModelRegistry $registry,
        private readonly YachtDesignService $design,
        private readonly ModelSheetQaService $qa,
    ) {}

    public const PROMPT_APPROVAL_EXECUTION = 'model-sheet-master-prompt-approval-v1';

    /** @var array<string, array<string, mixed>> panel thiet ke trong mot request (doc nhieu lan) */
    private array $designPanels = [];

    public static function execution(): string
    {
        return (string) config('video.superyacht.model_sheet.master.execution');
    }

    /** @return array{0: ?VideoPlanningStage, 1: ?string} */
    private function activeSet(string $projectId): array
    {
        try {
            [, $input, $snapshot] = $this->source($projectId);
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }

        [$stage, $token, $reason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::MODEL_SHEET, $input);

        if ($token === null) {
            return $reason === 'already_succeeded' && $stage !== null ? [$stage, null] : [null, 'Không mở được bộ model sheet ('.$reason.').'];
        }

        return $this->stageStore->finishSucceeded((string) $stage->id, $token, '', $snapshot)
            ? [VideoPlanningStage::query()->find($stage->id), null]
            : [null, 'Lượt mở bộ model sheet bị giành mất khi lưu — tải lại trang.'];
    }

    /**
     * Mot lan bam Render: mot request, mot master, dung prompt cua luot QA da dat. Bam trung
     * cung ma luot thi tra luot da co, khong mua them.
     *
     * @param  array{model: string, quality: string, attempt: string, qa_stage_id: string, prompt_sha256: string}  $choice
     * @return array{0: ?VideoDesignImage, 1: string}
     */
    public function render(string $projectId, string $creator, array $choice): array
    {
        try {
            [$set, $error] = $this->activeSet($projectId);
            $set ?? throw new RuntimeException((string) $error);
            $snapshot = (array) $set->output_json;
            $this->assertCompatible($snapshot);

            if (($snapshot['blocking'] ?? []) !== []) {
                throw new RuntimeException(implode(' ', (array) $snapshot['blocking']));
            }

            $qa = $this->qa->current($projectId, $set);

            if ($qa === null || (string) $qa->id !== $choice['qa_stage_id'] || ! ModelSheetQaService::ready($qa)) {
                throw new RuntimeException('Chưa có lượt QA đạt kiểm cho đúng bộ model sheet này — bấm "QA & hoàn thiện prompt" rồi tải lại trang.');
            }

            if (! hash_equals((string) $qa->output_json['prompt_sha256'], $choice['prompt_sha256'])
                || ! hash_equals((string) $qa->output_json['prompt_sha256'], hash('sha256', (string) $qa->output_json['prompt']))) {
                throw new RuntimeException('Prompt trên trang không còn khớp lượt QA đã lưu — tải lại trang.');
            }

            $spec = $this->spec($set, $qa, $this->settings($choice), $choice['attempt']);
            $this->approvePrompt($projectId, $qa, $creator);
        } catch (RuntimeException|InvalidArgumentException $e) {
            return [null, $e->getMessage()];
        }

        [$image, $reason] = $this->images->createModelSheetMaster($projectId, $creator, $spec);

        if ($image === null) {
            return [null, 'Không tạo được ô ảnh ('.$reason.').'];
        }

        if ($reason === 'already_exists') {
            return [$image, 'already_exists'];
        }

        [$image, $reason] = $this->renderer->renderNow((string) $image->id);

        if ($reason !== 'rendered' || $image === null) {
            return [$image, $reason];
        }

        [$processed, $message] = $this->processArtifact($set, $image, $image->artifacts()->orderBy('created_at')->orderBy('id')->first());

        return [$image, $processed ? 'rendered' : 'processing_failed|'.$message];
    }

    /**
     * Bam "QA & hoan thien prompt": mot luot Sonnet cho bo model sheet hien hanh. Ket qua dat
     * thi dung lai; `$retry` chi chay lai khi luot da luu chua dat kiem.
     *
     * @return array{0: ?VideoPlanningStage, 1: string}
     */
    public function qa(string $projectId, bool $retry): array
    {
        [$set, $error] = $this->activeSet($projectId);

        if ($set === null) {
            return [null, (string) $error];
        }

        if (($problem = $this->compatibilityError((array) $set->output_json)) !== null) {
            return [null, $problem];
        }

        return $this->qa->run($projectId, $set, $retry);
    }

    /** Nguoi dung xac nhan Render = chap nhan gui dung prompt QA nay (MODEL_SHEET_PROMPT_APPROVAL). */
    private function approvePrompt(string $projectId, VideoPlanningStage $qa, string $creator): void
    {
        [$stage, $token, $reason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::MODEL_SHEET_PROMPT_APPROVAL, [
            'execution' => self::PROMPT_APPROVAL_EXECUTION,
            'qa_stage_id' => (string) $qa->id,
            'prompt_sha256' => (string) $qa->output_json['prompt_sha256'],
        ]);

        if ($token !== null) {
            $this->stageStore->finishSucceeded((string) $stage->id, $token, '', ['approved_by' => $creator, 'approved_at' => now()->toIso8601String()]);
        } elseif ($reason !== 'already_succeeded') {
            throw new RuntimeException('Không ghi được xác nhận prompt ('.$reason.').');
        }
    }

    /**
     * Xu ly lai tu master da luu (kiem file, cat tam o). Khong goi model.
     *
     * @return array{0: bool, 1: string}
     */
    public function process(string $projectId, string $imageId): array
    {
        [$set, $error] = $this->activeSet($projectId);

        if ($set === null) {
            return [false, (string) $error];
        }

        $image = $this->masterOf($projectId, (string) $set->id, $imageId);

        if ($image === null || ! in_array($image->status, [DesignImageStatus::RENDERED->value, DesignImageStatus::APPROVED->value], true)) {
            return [false, 'Ảnh này không phải master đã render của bộ model sheet hiện tại.'];
        }

        return $this->processArtifact($set, $image, $image->artifacts()->orderBy('created_at')->orderBy('id')->first());
    }

    /**
     * Duyet CA master trong mot quyet dinh: duyet anh, ghi lan duyet bo model sheet (ID/hash
     * thiet ke, hash master). Day chua phai DESIGN LOCK.
     *
     * @return array{0: bool, 1: string}
     */
    public function approve(string $projectId, string $artifactId, ?string $adminId): array
    {
        [$set, $error] = $this->activeSet($projectId);

        if ($set === null) {
            return [false, (string) $error];
        }

        $artifact = VideoArtifact::query()->whereKey($artifactId)->where('project_id', $projectId)->first();
        $image = $artifact?->design_image_id === null ? null : $this->masterOf($projectId, (string) $set->id, (string) $artifact->design_image_id);

        if ($artifact === null || $image === null) {
            return [false, 'Ảnh này không thuộc bộ model sheet hiện tại.'];
        }

        if (($problem = $this->blockingProblem($image, $artifact, true)) !== null) {
            return [false, $problem];
        }

        $crops = $this->images->modelSheetCrops($projectId, $artifact);
        $input = $this->reviewInput($set, $image, $artifact);

        try {
            DB::transaction(function () use ($projectId, $artifactId, $adminId, $input, $crops): void {
                [$done, $reason] = $this->images->approve($projectId, $artifactId, $adminId, null, DesignImageStore::MODEL_SHEET_MASTER_TYPE);

                if (! $done) {
                    throw new RuntimeException('Không duyệt được ảnh ('.$reason.').');
                }

                [$stage, $token, $reason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::MODEL_SHEET_REVIEW, $input, true);

                if ($token === null) {
                    throw new RuntimeException('Không ghi được lượt duyệt model sheet ('.$reason.').');
                }

                $saved = $this->stageStore->finishSucceeded((string) $stage->id, $token, '', [
                    'approved_by' => $adminId,
                    'approved_at' => now()->toIso8601String(),
                    'crops' => array_map(static fn (array $crop): array => [
                        'view_id' => $crop['view_id'],
                        'artifact_id' => $crop['id'],
                        'sha256' => $crop['sha256'],
                        'x' => $crop['x'],
                        'y' => $crop['y'],
                        'width' => $crop['width'],
                        'height' => $crop['height'],
                    ], $crops),
                ]);

                if (! $saved) {
                    throw new RuntimeException('Lượt duyệt model sheet bị giành mất khi lưu — tải lại trang.');
                }
            });
        } catch (RuntimeException $e) {
            return [false, $e->getMessage()];
        }

        return [true, 'approved'];
    }

    /**
     * Tu choi ca master; lan thu sau tao master moi, giu lich su cu.
     *
     * @return array{0: bool, 1: string}
     */
    public function reject(string $projectId, string $imageId): array
    {
        [$set, $error] = $this->activeSet($projectId);

        if ($set === null) {
            return [false, (string) $error];
        }

        return $this->images->rejectModelSheetMaster($projectId, $imageId, (string) $set->id);
    }

    /**
     * Bo model sheet da duyet cua thiet ke hien hanh — nguon da duyet cho cac buoc tiep theo.
     * Chi doc: khong mo bo moi. Tim o moi execution duoc ho tro (bo duyet truoc khi doi bo
     * bien dich van hop le), kiem lai thiet ke nguon, file master/o theo sha256 va ban duyet.
     *
     * `$deep` doc lai file va so sha256 (kiem tra nguon); panel chi kiem file con ton tai.
     *
     * @return array{set_id: string, review_stage_id: string, design_stage_id: string, master_image_id: string, master_artifact_id: string, master_artifact_sha256: string}
     */
    public function approvedSheet(string $projectId, bool $deep = true): array
    {
        [$stage, $canonicalHash] = $this->approvedSource($projectId);
        $supported = array_map('strval', (array) config('video.superyacht.model_sheet.master.supported_executions'));
        $images = VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::MODEL_SHEET_MASTER_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(prompt_spec_json, '$.design_stage_id')) = ?", [(string) $stage->id])
            ->orderByDesc('approved_at')
            ->get();
        $problem = null;

        foreach ($images as $image) {
            $spec = (array) $image->prompt_spec_json;
            $set = VideoPlanningStage::query()->whereKey($spec['model_sheet_set_id'] ?? null)->where('project_id', $projectId)
                ->where('stage', PlanningStageName::MODEL_SHEET->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->first();
            $artifact = $image->selected_artifact_id === null ? null : VideoArtifact::query()->whereKey($image->selected_artifact_id)->where('project_id', $projectId)->first();

            if ($set === null || $artifact === null || ! in_array((string) ($set->input_json['execution'] ?? ''), $supported, true)
                || ($set->input_json['design_stage_id'] ?? null) !== (string) $stage->id || ($spec['design_canonical_hash'] ?? null) !== $canonicalHash) {
                continue;
            }

            if (($problem = $this->blockingProblem($image, $artifact, $deep)) !== null) {
                continue;
            }

            $review = $this->stageStore->latestSucceededForInput($projectId, PlanningStageName::MODEL_SHEET_REVIEW, $this->reviewInput($set, $image, $artifact));

            if ($review === null) {
                $problem = 'không tìm thấy lượt duyệt khớp master hiện hành';

                continue;
            }

            return [
                'set_id' => (string) $set->id,
                'review_stage_id' => (string) $review->id,
                'design_stage_id' => (string) $stage->id,
                'master_image_id' => (string) $image->id,
                'master_artifact_id' => (string) $artifact->id,
                'master_artifact_sha256' => (string) $artifact->sha256,
            ];
        }

        throw new RuntimeException($problem === null
            ? 'Model sheet của bản thiết kế hiện hành chưa được duyệt — duyệt model sheet trước.'
            : 'Model sheet đã duyệt không còn hợp lệ: '.$problem);
    }

    /** @return array<string, mixed> */
    public function panel(string $projectId, string $creator = ''): array
    {
        $design = $this->designPanel($projectId);
        [$active, $sourceError] = $this->activeSet($projectId);
        $set = $active ?? $this->currentSet($projectId);
        $config = (array) config('video.superyacht.model_sheet.master');

        $base = [
            'design_name' => $design['name'],
            'design_stage_id' => $design['stage_id'],
            'design_warnings' => count($design['check']['warnings'] ?? []),
            'source_error' => $sourceError,
            'models' => array_map(static fn (array $entry): array => [
                'value' => (string) $entry['model'],
                'label' => (string) $entry['label'],
                'default' => $entry['default'] === true,
            ], $this->registry->forTask(self::TASK)),
            'size' => (string) $config['size'],
            'variations' => (int) $config['variations'],
            'next_image_code' => trim($creator) === '' ? '' : $this->images->nextImageCode($projectId, $creator, self::CODE_SUFFIX),
            'set' => null,
            'master' => null,
            'masters' => [],
            'approved' => null,
            'complete' => false,
            'reviewed' => false,
            'qa' => null,
            'sheet' => null,
            'design_note' => null,
        ];

        try {
            $base['sheet'] = $this->approvedSheet($projectId, false);
        } catch (RuntimeException) {
        }

        if ($set === null) {
            return $base;
        }

        $snapshot = (array) $set->output_json;
        $masters = array_map(fn (array $cell): array => $cell + [
            'problems' => $this->cellProblems($cell),
        ], $this->images->modelSheetMastersFor($projectId, (string) $set->id));
        $approvedCell = array_values(array_filter($masters, static fn (array $cell): bool => $cell['status'] === DesignImageStatus::APPROVED->value))[0] ?? null;
        $approved = null;
        $reviewed = false;

        if ($approvedCell !== null && $approvedCell['selected_artifact_id'] !== null) {
            $image = $this->masterOf($projectId, (string) $set->id, (string) $approvedCell['id']);
            $artifact = VideoArtifact::query()->whereKey($approvedCell['selected_artifact_id'])->where('project_id', $projectId)->first();

            if ($image !== null && $artifact !== null) {
                $approved = [
                    'id' => (string) $image->id,
                    'artifact_id' => (string) $artifact->id,
                    'artifact_sha256' => (string) $artifact->sha256,
                    'problem' => $this->blockingProblem($image, $artifact),
                ];
                $reviewed = $approved['problem'] === null
                    && $this->stageStore->latestSucceededForInput($projectId, PlanningStageName::MODEL_SHEET_REVIEW, $this->reviewInput($set, $image, $artifact)) !== null;
            }
        }

        return [
            'set' => [
                'id' => (string) $set->id,
                'active' => $active !== null,
                'design_stage_id' => (string) ($set->input_json['design_stage_id'] ?? ''),
                'phase_revision' => (int) ($set->input_json['qa_phase_revision'] ?? $set->input_json['phase_revision'] ?? 0),
                'current_design' => ($set->input_json['design_stage_id'] ?? null) === $design['stage_id'],
                'compatible_error' => $this->compatibilityError($snapshot),
                'blocking' => array_values((array) ($snapshot['blocking'] ?? [])),
            ],
            'master' => [
                'draft' => (string) ($snapshot['facts']['draft'] ?? ''),
                'attempt' => (string) Str::uuid(),
                'layout_guide' => ($snapshot['layout_guide'] ?? false) === true,
                'views' => array_values((array) $snapshot['views']),
            ],
            'qa' => $active === null ? null : $this->qaState($projectId, $active),
            'masters' => $masters,
            'approved' => $approved,
            'complete' => $approved !== null && $approved['problem'] === null,
            'reviewed' => $reviewed,
        ] + $base;
    }

    /**
     * Trang thai QA cua bo dang mo cho panel: prompt cuoi, thay doi, loi kiem, findings, chi phi.
     *
     * @return array<string, mixed>
     */
    private function qaState(string $projectId, VideoPlanningStage $set): array
    {
        $snapshot = (array) $set->output_json;
        $stage = $this->qa->current($projectId, $set);
        $output = (array) ($stage?->output_json ?? []);
        $status = $stage?->status;
        $running = $status === VideoPlanningStageStatus::RUNNING->value && $stage->lease_expires_at?->isFuture();
        $ready = ModelSheetQaService::ready($stage);
        $config = (array) config('video.superyacht.model_sheet.qa');

        return [
            'stage_id' => $stage === null ? null : (string) $stage->id,
            'status' => $stage === null ? 'none' : ($running ? 'running' : (string) $status),
            'ready' => $ready,
            'error' => $status === VideoPlanningStageStatus::FAILED->value ? (string) $stage->error_message : null,
            'stream' => PlanningStageStore::streamOf($stage),
            'prompt' => $ready ? (string) $output['prompt'] : null,
            'prompt_sha256' => $ready ? (string) $output['prompt_sha256'] : null,
            'validation' => self::shortValidation((array) ($output['validation'] ?? [])),
            'cost_usd' => $stage === null ? null : (float) $stage->cost_usd,
            'tokens' => $stage === null ? null : ['in' => (int) $stage->tokens_in, 'out' => (int) $stage->tokens_out],
            'model' => (string) $config['model'],
            'can_run' => ! $running && ($snapshot['blocking'] ?? []) === [] && $this->compatibilityError($snapshot) === null && ! $ready,
            'retry' => $stage !== null && $status === VideoPlanningStageStatus::SUCCEEDED->value && ! $ready,
            'estimate' => '≈ $'.number_format(((float) $config['pricing']['input_usd_per_mtok'] * (int) $config['estimate_tokens']['input'] + (float) $config['pricing']['output_usd_per_mtok'] * (int) $config['estimate_tokens']['output']) / 1000000, 2)
                .', tối đa ≈ $'.number_format(((float) $config['pricing']['input_usd_per_mtok'] * (int) $config['estimate_tokens']['input'] + (float) $config['pricing']['output_usd_per_mtok'] * (int) $config['max_tokens']) / 1000000, 2),
        ];
    }

    /**
     * Gioi han so loi hien tren panel; ban day du nam trong output_json cua stage QA.
     *
     * @param  array<string, mixed>  $validation
     * @return array{errors: list<string>, blocking: list<string>, notes: list<string>}
     */
    private static function shortValidation(array $validation, int $limit = 20): array
    {
        $out = [];

        foreach (['errors', 'blocking', 'notes'] as $key) {
            $lines = array_values((array) ($validation[$key] ?? []));
            $out[$key] = count($lines) > $limit
                ? [...array_slice($lines, 0, $limit), '… và '.(count($lines) - $limit).' dòng khác (đầy đủ trong kết quả lượt QA).']
                : $lines;
        }

        return $out;
    }

    /**
     * Dau vao cua lan duyet bo: ID/hash thiet ke va hash master. Thiet ke doi thi lan
     * duyet cu khong con la dau vao hop le.
     *
     * @return array<string, mixed>
     */
    private function reviewInput(VideoPlanningStage $set, VideoDesignImage $image, VideoArtifact $artifact): array
    {
        return [
            'execution' => self::REVIEW_EXECUTION,
            'model_sheet_set_id' => (string) $set->id,
            'design_stage_id' => (string) ($set->input_json['design_stage_id'] ?? ''),
            'design_canonical_hash' => (string) ($set->input_json['design_canonical_hash'] ?? ''),
            'master_image_id' => (string) $image->id,
            'master_artifact_id' => (string) $artifact->id,
            'master_artifact_sha256' => (string) $artifact->sha256,
        ];
    }

    /**
     * Loi chan duyet: thieu o, o sai kich thuoc hoac sai lien ket master, xu ly loi, file
     * master/o mat. `$deep` doc lai file va so sha256 (duyet, xu ly lai, kiem tra nguon); panel
     * chi kiem file con ton tai.
     */
    private function blockingProblem(VideoDesignImage $image, VideoArtifact $artifact, bool $deep = false): ?string
    {
        if (! in_array($image->status, [DesignImageStatus::RENDERED->value, DesignImageStatus::APPROVED->value], true)) {
            return 'Master ở trạng thái '.$image->status.' — không duyệt được.';
        }

        if (($problem = self::fileProblem((string) $artifact->storage_disk, (string) $artifact->storage_path, (string) $artifact->sha256, $deep)) !== null) {
            return 'File master '.$problem.' — cần render lại.';
        }

        $processing = (array) (($artifact->metadata_json ?? [])['model_sheet'] ?? []);

        if (($processing['ok'] ?? null) !== true) {
            return 'Master chưa được xử lý xong'.(is_string($processing['error'] ?? null) ? ' ('.$processing['error'].')' : '').' — bấm "Xử lý lại từ master".';
        }

        return $this->cropProblem($this->images->modelSheetCrops((string) $image->project_id, $artifact), $artifact, $deep);
    }

    /**
     * Bo o hien hanh cua master: du tam o dung thu tu, dung kich thuoc/toa do/hash master,
     * file con va (khi `$deep`) dung sha256.
     *
     * @param  list<array<string, mixed>>  $crops
     */
    private function cropProblem(array $crops, VideoArtifact $artifact, bool $deep): ?string
    {
        $cells = ModelSheetMaster::fromConfig()->cells();

        if (array_column($crops, 'view_id') !== array_column($cells, 'id')) {
            return 'Master chưa có đủ '.count($cells).' ô theo đúng thứ tự — bấm "Xử lý lại từ master".';
        }

        foreach ($crops as $index => $crop) {
            $cell = $cells[$index];

            if ($crop['width'] !== $cell['width'] || $crop['height'] !== $cell['height'] || $crop['x'] !== $cell['x'] || $crop['y'] !== $cell['y']
                || $crop['master_sha256'] !== (string) $artifact->sha256) {
                return "Ô {$crop['view_id']} sai kích thước, tọa độ hoặc liên kết master.";
            }

            if (($problem = self::fileProblem($crop['disk'], $crop['path'], $crop['sha256'], $deep)) !== null) {
                return "File ô {$crop['view_id']} {$problem} — bấm \"Xử lý lại từ master\".";
            }
        }

        return null;
    }

    private static function fileProblem(string $disk, string $path, string $sha256, bool $deep): ?string
    {
        $filesystem = Storage::disk($disk);

        if ($path === '' || ! $filesystem->exists($path)) {
            return 'không còn trên đĩa';
        }

        if ($deep) {
            $bytes = $filesystem->get($path);

            if (! is_string($bytes) || ! hash_equals($sha256, hash('sha256', $bytes))) {
                return 'không khớp sha256';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $cell
     * @return array<string, ?string> artifact_id => loi chan (null neu duyet duoc)
     */
    private function cellProblems(array $cell): array
    {
        $problems = [];
        $image = VideoDesignImage::query()->find($cell['id']);

        foreach ($cell['candidates'] as $candidate) {
            $artifact = VideoArtifact::query()->find($candidate['id']);
            $problems[(string) $candidate['id']] = $image === null || $artifact === null ? 'Không đọc được master.' : $this->blockingProblem($image, $artifact);
        }

        return $problems;
    }

    /**
     * Kiem file, cat tam o, do canh bao, ghi o va ket qua vao metadata master.
     *
     * @return array{0: bool, 1: string}
     */
    private function processArtifact(VideoPlanningStage $set, VideoDesignImage $image, ?VideoArtifact $artifact): array
    {
        if ($artifact === null) {
            return [false, 'Master không có file ảnh.'];
        }

        $valid = fn (array $crops, VideoArtifact $current): bool => (($current->metadata_json ?? [])['model_sheet']['ok'] ?? null) === true
            && $this->cropProblem($crops, $current, true) === null;
        $master = ModelSheetMaster::fromConfig();
        $snapshot = (array) $set->output_json;
        $filesystem = Storage::disk((string) $artifact->storage_disk);

        try {
            // Master la nguon cua bo o: kiem truoc moi duong ra, ke ca duong tat ben duoi.
            $bytes = $filesystem->exists((string) $artifact->storage_path) ? $filesystem->get((string) $artifact->storage_path) : null;

            if (! is_string($bytes) || $bytes === '' || ! hash_equals((string) $artifact->sha256, hash('sha256', $bytes))) {
                throw new RuntimeException('File master không còn trên đĩa hoặc không khớp sha256 — không xử lý lại được, cần render lại.');
            }

            if (($snapshot['layout_sha256'] ?? null) !== $master->layoutSha256()) {
                throw new RuntimeException('Bố cục trong config đã đổi so với lúc mở bộ model sheet.');
            }
        } catch (RuntimeException $e) {
            $this->images->recordModelSheetProcessing($artifact, ['ok' => false, 'error' => $e->getMessage(), 'processed_at' => now()->toIso8601String()]);

            return [false, $e->getMessage()];
        }

        // Duong tat: bo o da du va dung thi khong cat lai. Thieu/hong thi cat lai tu master
        // da luu; kho kiem lai tren dong master da khoa truoc khi ghi.
        if ($valid($this->images->modelSheetCrops((string) $image->project_id, $artifact), $artifact)) {
            return [true, 'already_processed'];
        }

        try {
            $decoded = $master->decode($bytes);
            $crops = $master->crop($decoded);
        } catch (RuntimeException $e) {
            $this->images->recordModelSheetProcessing($artifact, ['ok' => false, 'error' => $e->getMessage(), 'processed_at' => now()->toIso8601String()]);

            return [false, $e->getMessage()];
        }

        try {
            $stored = $this->images->storeModelSheetCrops((string) $image->project_id, $artifact, (string) $set->id, $crops, [
                'ok' => true,
                'processed_at' => now()->toIso8601String(),
                'master_sha256' => (string) $artifact->sha256,
                'layout_sha256' => (string) $snapshot['layout_sha256'],
            ], $valid);
        } catch (Throwable $e) {
            $this->images->recordModelSheetProcessing($artifact->refresh(), ['ok' => false, 'error' => 'Không lưu được ô cắt: '.$e->getMessage(), 'processed_at' => now()->toIso8601String()]);

            return [false, 'Không lưu được ô cắt: '.$e->getMessage()];
        }

        return [true, $stored];
    }

    private function masterOf(string $projectId, string $setId, string $imageId): ?VideoDesignImage
    {
        $image = VideoDesignImage::query()
            ->whereKey($imageId)
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::MODEL_SHEET_MASTER_TYPE)
            ->first();

        return $image !== null && (($image->prompt_spec_json ?? [])['model_sheet_set_id'] ?? null) === $setId ? $image : null;
    }

    /**
     * Nguon chung cho model sheet: project thuoc workflow cua category yacht, ban thiet ke Phase 3
     * hien hanh dat kiem, raw JSON va hash canonical. Khong doi truong rieng cua mot tau.
     *
     * @return array{0: VideoPlanningStage, 1: array<string, mixed>, 2: string, 3: string, 4: string}
     */
    private function context(string $projectId): array
    {
        $project = VideoProject::query()->with('article.category')->find($projectId) ?? throw new RuntimeException('Không tìm thấy dự án.');
        $category = $project->article?->category ?? throw new RuntimeException('Dự án không gắn bài viết có chủ đề — model sheet không áp dụng.');
        $workflow = (string) (config('video.superyacht.contracts.categories')[$category->slug] ?? '');

        if ($workflow === '' || $workflow !== (string) config('video.superyacht.workflow_code')) {
            throw new RuntimeException("Model sheet chỉ áp dụng cho chủ đề ánh xạ tới workflow ".config('video.superyacht.workflow_code')."; chủ đề {$category->slug} không thuộc workflow này.");
        }

        $panel = $this->designPanel($projectId);

        // Ban thiet ke da chay (theo prompt cua chinh no) van la nguon khi prompt Phase 3 len revision
        // moi; chi DNA nguon doi moi lam ban cu het hieu luc.
        if ($panel['dna_stale'] || ($panel['check']['verdict'] ?? null) !== YachtDesignCheck::PASS || ! is_string($panel['stage_id'])) {
            throw new RuntimeException('Chưa có bản thiết kế Phase 3 đạt kiểm tự động cho Design DNA hiện hành.');
        }

        $stage = VideoPlanningStage::query()->whereKey($panel['stage_id'])->where('project_id', $projectId)->first();

        if ($stage === null || $stage->status !== VideoPlanningStageStatus::SUCCEEDED->value) {
            throw new RuntimeException('Bản thiết kế nguồn không thuộc dự án hoặc chưa chạy thành công.');
        }

        $raw = (string) $stage->raw_response;
        $design = JsonAnswer::object($raw);
        $canonicalHash = $stage->output_json['design']['data']['canonical']['hash'] ?? null;

        if ($design === null || ! is_string($canonicalHash) || $canonicalHash === '') {
            throw new RuntimeException('Bản thiết kế nguồn không đọc được JSON hoặc thiếu hash canonical.');
        }

        return [$stage, $design, $canonicalHash, (string) $category->id, $workflow];
    }


    /**
     * Ban thiet ke nguon cua model sheet da duyet: ban hien hanh (khop cau hinh chay moi) neu co;
     * khong thi luot thiet ke thanh cong MOI NHAT cua du an voi ket qua kiem da luu dat PASS. Doi
     * prompt/hop dong phase-3 cho luot thiet ke moi khong lam master, QA, canonical da duyet cua ban
     * cu het hieu luc; chi mot luot thiet ke moi thanh cong moi thay nguon.
     *
     * @return array{0: VideoPlanningStage, 1: string}
     */
    public function approvedSource(string $projectId): array
    {
        try {
            [$stage, , $canonicalHash] = $this->context($projectId);

            return [$stage, $canonicalHash];
        } catch (RuntimeException $current) {
            $project = VideoProject::query()->with('article.category')->find($projectId);
            $workflow = (string) (config('video.superyacht.contracts.categories')[$project?->article?->category?->slug ?? ''] ?? '');
            $stage = VideoPlanningStage::query()
                ->where('project_id', $projectId)
                ->where('stage', PlanningStageName::YACHT_DESIGN->value)
                ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
                ->orderByDesc('planning_revision')
                ->first();
            $canonicalHash = $stage?->output_json['design']['data']['canonical']['hash'] ?? null;

            if ($workflow === '' || $workflow !== (string) config('video.superyacht.workflow_code') || $stage === null
                || ($stage->output_json['check']['verdict'] ?? null) !== YachtDesignCheck::PASS || ! is_string($canonicalHash) || $canonicalHash === '') {
                throw $current;
            }

            return [$stage, $canonicalHash];
        }
    }

    /** @return array<string, mixed> */
    private function designPanel(string $projectId): array
    {
        return $this->designPanels[$projectId] ??= $this->design->panel($projectId);
    }

    /** @return array{0: VideoPlanningStage, 1: array<string, mixed>, 2: array<string, mixed>} */
    private function source(string $projectId): array
    {
        [$stage, $design, $canonicalHash, $categoryId, $workflow] = $this->context($projectId);
        $config = (array) config('video.superyacht.model_sheet');
        $designPhase = AiPhase::query()->find($stage->input_json['phase_id'] ?? null) ?? throw new RuntimeException('Không tìm thấy prompt phase-3 mà bản thiết kế đã dùng.');
        $qaPhase = AiPhase::query()
            ->where('category_id', $categoryId)
            ->where('workflow_code', $workflow)
            ->where('phase_code', (string) $config['qa']['phase_code'])
            ->where('is_current', true)
            ->where('status', AiPhase::PUBLISHED)
            ->get();

        if ($qaPhase->count() !== 1 || ! hash_equals((string) $qaPhase->first()->content_sha256, hash('sha256', (string) $qaPhase->first()->prompt_content))) {
            throw new RuntimeException('Không có đúng một revision hiện hành hợp lệ của prompt QA '.$config['qa']['phase_code'].' cho chủ đề này.');
        }

        $qaPhase = $qaPhase->first();
        $dnaStage = VideoPlanningStage::query()->whereKey($stage->input_json['dna_stage_id'] ?? null)->where('project_id', $projectId)->first();
        $dna = $dnaStage === null ? [] : (array) (JsonAnswer::object((string) ($dnaStage->output_json['raw_text'] ?? ''))['dna'] ?? []);
        $master = ModelSheetMaster::fromConfig();
        $views = self::views($config, $master, $design);
        $facts = (new ModelSheetFacts($design, $dna, array_map(static fn (array $v): array => ['id' => $v['id'], 'view' => $v['view']], $views), (array) $config['qa']['priority']))->snapshot();
        $guide = ($config['master']['layout_guide'] ?? false) === true;
        $snapshot = [
            'execution' => self::execution(),
            'design_phase' => ['id' => (string) $designPhase->id, 'revision' => (int) $designPhase->revision, 'sha256' => (string) $designPhase->content_sha256],
            'qa_phase' => ['id' => (string) $qaPhase->id, 'revision' => (int) $qaPhase->revision, 'sha256' => (string) $qaPhase->content_sha256],
            'layout_sha256' => $master->layoutSha256(),
            'layout_guide' => $guide,
            'guide_sha256' => $guide ? hash('sha256', $master->guidePng()) : null,
            'policy_sha256' => self::policySha256(),
            // Giu trong snapshot: la mot phan dinh danh cua cac bo model sheet da mo (bo dang chua
            // master va prompt QA da duyet). Bo khoa nay se doi dinh danh, panel mat bo hien hanh.
            'expected_ratios' => self::expectedRatios((array) (self::at($design, 'data.canonical') ?? [])),
            'views' => $views,
            'facts' => $facts,
            'facts_sha256' => hash('sha256', (string) json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'blocking' => array_values(array_filter([
                $facts['coordinate_problem'],
                $facts['minimum_problems'] === [] ? null : 'Thiết kế thiếu dữ liệu nền tảng cho model sheet: '.implode(', ', $facts['minimum_problems']).'.',
                $facts['conflicts'] === [] ? null : 'Dữ liệu nguồn mâu thuẫn (sửa ở thiết kế): '.implode(' ', $facts['conflicts']),
                $facts['ms8']['problem'] ?? null,
            ])),
        ];

        $input = [
            'execution' => self::execution(),
            'workflow' => $workflow,
            'design_stage_id' => (string) $stage->id,
            'design_raw_sha256' => hash('sha256', (string) $stage->raw_response),
            'design_canonical_hash' => $canonicalHash,
            'dna_stage_id' => $dnaStage === null ? null : (string) $dnaStage->id,
            'design_phase_id' => (string) $designPhase->id,
            'qa_phase_id' => (string) $qaPhase->id,
            'qa_phase_revision' => (int) $qaPhase->revision,
            'qa_prompt_sha256' => (string) $qaPhase->content_sha256,
            'layout_sha256' => $snapshot['layout_sha256'],
            'config_sha256' => hash('sha256', (string) json_encode(array_diff_key($snapshot, array_flip(['execution'])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];

        return [$stage, $input, $snapshot];
    }

    /**
     * Ti le canh dai/canh ngan cua khung bao tau theo thiet ke. Chi con nam trong snapshot de giu
     * dinh danh cac bo model sheet da mo; khong doi cach tinh (doi la doi dinh danh bo).
     *
     * @param  array<string, mixed>  $canonical
     * @return array<string, float>
     */
    private static function expectedRatios(array $canonical): array
    {
        $number = static fn (mixed $value): ?float => is_int($value) || is_float($value) ? (float) $value : null;
        $loa = $number($canonical['dimensions']['loa']['value'] ?? null);
        $beam = $number($canonical['dimensions']['beam']['value'] ?? null);
        $draft = $number($canonical['dimensions']['draft']['value'] ?? null);
        $tops = array_filter(array_map(static fn (mixed $point): ?float => is_array($point) ? $number($point[1] ?? null) : null, (array) ($canonical['profile_curve'] ?? [])), static fn (?float $z): bool => $z !== null);
        $height = $tops === [] || $draft === null ? null : max($tops) + $draft;
        $ratio = static fn (?float $a, ?float $b): ?float => $a === null || $b === null || min($a, $b) <= 0 ? null : max($a, $b) / min($a, $b);

        return array_filter([
            'MS1' => $ratio($loa, $height),
            'MS4' => $ratio($beam, $height),
            'MS5' => $ratio($beam, $height),
            'MS6' => $ratio($loa, $beam),
        ], static fn (?float $value): bool => $value !== null);
    }

    /**
     * Phien ban chinh sach ghep (camera, bo cuc, quy tac, trinh bay, phien ban schema/compiler/
     * bang du kien). Thay vi so nguyen van bang camera trong prompt Phase 3.
     */
    private static function policySha256(): string
    {
        $config = (array) config('video.superyacht.model_sheet');

        return hash('sha256', (string) json_encode([
            'views' => $config['views'],
            'cameras' => $config['cameras'],
            'prompt' => $config['master']['prompt'],
            'occlusion' => $config['occlusion'],
            'suffix' => $config['suffix'],
            'facts' => ModelSheetFacts::VERSION,
            'schema' => ModelSheetQaValidator::VERSION,
            'compiler' => ModelSheetPromptCompiler::VERSION,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Goc theo thu tu    /**
     * Goc theo thu tu trong config (MS1..MS8), kem vi tri o. Nhan MS8 lay tu ban thiet ke neu
     * thiet ke tu khai muc tieu chi tiet.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $design
     * @return list<array{id: string, view: string, row: int, column: int, x: int, y: int, width: int, height: int}>
     */
    private static function views(array $config, ModelSheetMaster $master, array $design = []): array
    {
        $cells = [];

        foreach ($master->cells() as $cell) {
            $cells[$cell['id']] = $cell;
        }

        $views = [];

        foreach (array_values((array) $config['views']) as $view) {
            $cell = $cells[(string) $view['id']] ?? throw new RuntimeException("Góc {$view['id']} không có ô trong bố cục master.");
            $views[] = ['id' => (string) $view['id'], 'view' => self::designLabel($design, (string) $view['id']) ?? (string) $view['view']] + array_intersect_key($cell, array_flip(['row', 'column', 'x', 'y', 'width', 'height']));
        }

        if (count($views) !== count($cells)) {
            throw new RuntimeException('Bố cục master có ô không thuộc danh sách góc model sheet.');
        }

        return $views;
    }

    /**
     * Kiem theo phien ban: bo cuc, chinh sach ghep (camera, quy tac, trinh bay, schema/compiler)
     * va prompt QA da chot. Khong so nguyen van bang camera/cau nen trong prompt Phase 3.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function compatibilityError(array $snapshot): ?string
    {
        $master = ModelSheetMaster::fromConfig();

        if (($snapshot['execution'] ?? null) !== self::execution()) {
            return 'Bộ model sheet này thuộc execution '.($snapshot['execution'] ?? '?').', không phải '.self::execution().' — chỉ xem, không render.';
        }

        if (($snapshot['layout_sha256'] ?? null) !== $master->layoutSha256()) {
            return 'Bố cục master trong config đã đổi so với lúc mở bộ model sheet.';
        }

        if (($snapshot['policy_sha256'] ?? null) !== self::policySha256()) {
            return 'Camera, quy tắc hoặc phiên bản bộ ghép đã đổi so với lúc mở bộ model sheet.';
        }

        $ids = array_column((array) ($snapshot['views'] ?? []), 'id');

        if ($ids !== array_column($master->cells(), 'id')) {
            return 'Danh sách góc của bộ ('.implode(', ', $ids).') không khớp bố cục master.';
        }

        foreach ($ids as $id) {
            if (trim((string) config('video.superyacht.model_sheet.cameras.'.$id, '')) === '') {
                return "Config model sheet thiếu thông số camera của {$id}.";
            }
        }

        $phase = AiPhase::query()->find($snapshot['qa_phase']['id'] ?? null);

        if ($phase === null || $phase->status !== AiPhase::PUBLISHED || (string) $phase->content_sha256 !== (string) ($snapshot['qa_phase']['sha256'] ?? '')) {
            return 'Prompt QA model sheet mà bộ chốt không còn khớp hash.';
        }

        return null;
    }

    /**
     * Nhan goc ma ban thiet ke tu khai (canonical.model_sheet.views), vi du muc tieu MS8.
     *
     * @param  array<string, mixed>  $design
     */
    private static function designLabel(array $design, string $id): ?string
    {
        foreach ((array) (self::at($design, 'data.canonical.model_sheet.views') ?? []) as $view) {
            if (is_array($view) && ($view['id'] ?? null) === $id && is_string($view['view'] ?? null) && trim($view['view']) !== '') {
                return $id === 'MS8' ? trim($view['view']) : null;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $design */
    private static function at(array $design, string $path): mixed
    {
        $node = $design;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $node;
    }

    /** @param  array<string, mixed>  $snapshot */
    private function assertCompatible(array $snapshot): void
    {
        if (($error = $this->compatibilityError($snapshot)) !== null) {
            throw new RuntimeException($error.' Không gửi request.');
        }
    }

    /**
     * Kich thuoc va so anh co dinh theo config master; nguoi dung chi chon model va quality.
     *
     * @param  array{model: string, quality: string}  $choice
     * @return array{entry: array<string, mixed>, size: ImageSize, quality: ImageQuality, variations: ImageVariations}
     */
    private function settings(array $choice): array
    {
        $config = (array) config('video.superyacht.model_sheet.master');
        $entry = $this->registry->find(self::TASK, 'openai:'.$choice['model']) ?? throw new RuntimeException("Model {$choice['model']} không dùng được cho model sheet.");
        $model = ImageModel::tryFrom($choice['model']) ?? throw new RuntimeException("Model {$choice['model']} không có trong ImageModel.");
        $size = ImageSize::tryFrom((string) $config['size']);
        $quality = ImageQuality::tryFrom($choice['quality']);
        $variations = ImageVariations::tryFrom((int) $config['variations']);

        if ($size === null || ! in_array($size->value, $entry['controls']['sizes'], true)) {
            throw new RuntimeException("Size {$config['size']} không dùng được với {$entry['label']}.");
        }

        if ($quality === null || ! $model->supports($quality)) {
            throw new RuntimeException("Quality {$choice['quality']} không dùng được với {$entry['label']}.");
        }

        if ($variations === null || $variations->value > (int) $entry['max_variations']) {
            throw new RuntimeException("{$config['variations']} ảnh vượt mức tối đa của {$entry['label']}.");
        }

        return ['entry' => $entry, 'size' => $size, 'quality' => $quality, 'variations' => $variations];
    }

    /**
     * Dong bang luot render: prompt, bo cuc, thiet ke nguon, gia (DesignImageStore).
     *
     * @param  array{entry: array<string, mixed>, size: ImageSize, quality: ImageQuality, variations: ImageVariations}  $settings
     * @return array<string, mixed>
     */
    private function spec(VideoPlanningStage $set, VideoPlanningStage $qa, array $settings, string $attempt): array
    {
        $snapshot = (array) $set->output_json;
        $prompt = (string) $qa->output_json['prompt'];

        return [
            'project_id' => (string) $set->project_id,
            'operation' => self::MASTER_OPERATION,
            'task' => self::TASK,
            'execution' => self::execution(),
            'model_sheet_set_id' => (string) $set->id,
            'design_stage_id' => (string) $set->input_json['design_stage_id'],
            'design_canonical_hash' => (string) $set->input_json['design_canonical_hash'],
            'layout_sha256' => (string) $snapshot['layout_sha256'],
            'layout_guide' => ($snapshot['layout_guide'] ?? false) === true,
            'guide_sha256' => $snapshot['guide_sha256'] ?? null,
            'slot_index' => 0,
            'attempt' => $attempt,
            'qa_stage_id' => (string) $qa->id,
            'prompt' => $prompt,
            'prompt_sha256' => hash('sha256', $prompt),
            'provider' => $settings['entry']['provider'],
            'model' => $settings['entry']['model'],
            'pricing' => $settings['entry']['pricing'],
            'size' => $settings['size']->value,
            'quality' => $settings['quality']->value,
            'variations' => $settings['variations']->value,
            'aspect_ratio' => null,
            'image_size' => null,
        ];
    }

    private function currentSet(string $projectId): ?VideoPlanningStage
    {
        return VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::MODEL_SHEET->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->whereNull('input_json->orphan_of_stage_id')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.execution')) = ?", [self::execution()])
            ->orderByDesc('planning_revision')
            ->first();
    }
}
