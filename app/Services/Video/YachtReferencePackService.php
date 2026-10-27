<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\AiJsonSchema;
use App\Models\AiPhase;
use App\Models\AiPhaseSchema;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Video\Llm\JsonAnswer;
use App\Video\Media\MediaModelRegistry;
use App\Video\ReferencePack\ReferenceColorSheet;
use App\Video\ReferencePack\ReferencePackCompiler as Compiler;
use App\Video\ReferencePack\ReferencePackSelector;
use App\Video\ReferencePack\ReferencePackValidator as Validator;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Phase 3R — bo anh tham chieu co phien ban. Dieu phoi: lap ke hoach (ghim snapshot nguon tu mot
 * luot duyet model sheet cu the), tao phan con thieu, kiem va dung manifest, duyet va chon pack.
 *
 * Nguon bat bien: sau khi lap, moi thao tac chi doc snapshot cua ke hoach — khong goi lai "thiet ke
 * moi nhat". Prompt len revision, render master khac hay tao thiet ke moi khong sua pack cu; muon
 * nguon khac thi lap pack khac. Mo trang chi doc: khong tao stage, khong render, khong doi pack.
 */
final class YachtReferencePackService
{
    public const PLAN_EXECUTION = 'reference-pack-plan-v1';

    public const PACK_EXECUTION = 'reference-pack-v1';

    public const REVIEW_EXECUTION = 'reference-pack-review-v1';

    public const SELECTION_KEY = 'reference_pack_selection';

    public const PHASE_CODE = 'phase-3r';

    /** So muc toi da moi lan bam "Tao phan con thieu" (render dong bo trong request). */
    public const MAX_RENDER_ITEMS = 6;

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly DesignImageStore $images,
        private readonly DesignImageDirectRenderer $renderer,
        private readonly MediaModelRegistry $registry,
        private readonly ModelSheetService $modelSheet,
        private readonly DesignLockService $locks,
    ) {}

    /**
     * Lap bo tham chieu tu mot luot duyet model sheet cu the (khong goi AI). Cung nguon thi dung lai
     * ke hoach da co.
     *
     * @return array{0: ?VideoPlanningStage, 1: string}
     */
    public function plan(string $projectId, string $reviewStageId): array
    {
        try {
            [$sources, $facts, $labels] = $this->source($projectId, $reviewStageId);
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }

        $items = array_map(fn (array $item): array => $this->prepare($item, $facts, $sources), Compiler::plan($facts, $labels));
        [$stage, $token, $reason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::REFERENCE_PACK_PLAN, [
            'execution' => self::PLAN_EXECUTION,
            'review_stage_id' => $reviewStageId,
            'design_raw_sha256' => $sources['design_raw_sha256'],
            'master_sha256' => $sources['model_sheet']['master_sha256'],
            'phase_id' => $sources['phase']['id'],
            'phase_sha256' => $sources['phase']['sha256'],
            'compiler_version' => Compiler::VERSION,
        ]);

        if ($token === null && ($reason !== 'already_succeeded' || $stage === null)) {
            return [null, 'Không lập được bộ tham chiếu ('.$reason.').'];
        }

        if ($token !== null && ! $this->stageStore->finishSucceeded((string) $stage->id, $token, '', [
            'execution' => self::PLAN_EXECUTION,
            'sources' => $sources,
            'facts' => $facts,
            'view_labels' => $labels,
            'items' => $items,
            'compiler_version' => Compiler::VERSION,
            'policy_version' => Validator::POLICY_VERSION,
        ])) {
            return [null, 'Lượt lập bộ tham chiếu bị giành mất khi lưu — tải lại trang.'];
        }

        $stage = VideoPlanningStage::query()->findOrFail($stage->id);

        // Bang mau dung bang code ngay khi lap (mien phi, khop HEX tuyet doi).
        foreach ((array) $stage->output_json['items'] as $item) {
            if ($item['method'] === Compiler::METHOD_CODE && $item['group'] === 'colors') {
                $png = ReferenceColorSheet::png($item['colors']);
                $this->images->storeReferencePackCode($projectId, (string) $stage->id, $item['id'], $png, ReferenceColorSheet::WIDTH, ReferenceColorSheet::HEIGHT, [
                    'generator' => ReferenceColorSheet::VERSION,
                    'colors' => $item['colors'],
                ]);
            }
        }

        return [$stage, $token === null ? 'existing' : 'planned'];
    }

    /**
     * Tao cac muc render da chon (dong bo, toi da MAX_RENDER_ITEMS). Muc da co anh dung duoc thi bo
     * qua — khong render lai; mot muc loi khong anh huong muc khac.
     *
     * @param  list<string>  $itemIds
     * @param  array{model: string, quality: string, attempt: string}  $choice
     * @return array{0: list<array{item_id: string, reason: string}>, 1: string}
     */
    public function render(string $projectId, string $planId, array $itemIds, array $choice, string $creator): array
    {
        $plan = $this->planStage($projectId, $planId);

        if ($plan === null) {
            return [[], 'Không tìm thấy bộ tham chiếu.'];
        }

        if (($problem = self::contractProblem($plan)) !== null) {
            return [[], $problem];
        }

        if (count($itemIds) > self::MAX_RENDER_ITEMS) {
            return [[], 'Mỗi lần chỉ tạo tối đa '.self::MAX_RENDER_ITEMS.' mục.'];
        }

        try {
            $entry = $this->settings($choice['model'], $choice['quality']);
        } catch (RuntimeException $e) {
            return [[], $e->getMessage()];
        }

        $items = array_column((array) $plan->output_json['items'], null, 'id');
        $out = [];

        foreach (array_values(array_unique($itemIds)) as $itemId) {
            $item = $items[$itemId] ?? null;

            if ($item === null || $item['method'] !== Compiler::METHOD_RENDER) {
                $out[] = ['item_id' => $itemId, 'reason' => 'Mục này không tạo bằng render.'];

                continue;
            }

            $spec = [
                'project_id' => $projectId,
                'operation' => DesignImageStore::REFERENCE_PACK_ITEM_TYPE,
                'plan_id' => $planId,
                'item_id' => $itemId,
                'prompt' => $item['prompt'],
                'prompt_sha256' => $item['prompt_sha256'],
                'sources' => $item['resolved_inputs'],
                'sources_sha256' => hash('sha256', (string) json_encode($item['resolved_inputs'])),
                'provider' => $entry['provider'],
                'model' => $entry['model'],
                'pricing' => $entry['pricing'],
                'size' => ($item['kind'] ?? null) === 'materials' ? '1024x1024' : '1536x1024',
                'quality' => $choice['quality'],
                'variations' => 1,
                'attempt' => $choice['attempt'],
                'compiler_version' => Compiler::VERSION,
                'aspect_ratio' => null,
                'image_size' => null,
            ];
            [$image, $created] = $this->images->createReferencePackItem($projectId, $creator, $spec);

            if ($image === null) {
                $out[] = ['item_id' => $itemId, 'reason' => 'Không tạo được ô ảnh ('.$created.').'];

                continue;
            }

            if ($created === 'in_flight') {
                $out[] = ['item_id' => $itemId, 'reason' => 'Đã có ảnh hoặc đang tạo — không render lại.'];

                continue;
            }

            try {
                // Chi nhan candidate: o loi khong tu render lai (luot sau la o moi); claim chi mot ben thang.
                [, $reason] = $this->renderer->renderNow((string) $image->id, [DesignImageStatus::CANDIDATE->value]);
            } catch (Throwable $e) {
                Log::error('reference pack: render threw', ['image_id' => $image->id, 'exception' => $e]);
                $reason = 'lỗi: '.$e->getMessage();
            }

            $out[] = ['item_id' => $itemId, 'reason' => (string) $reason];
        }

        return [$out, 'done'];
    }

    /**
     * Ghi DESIGN LOCK cho dung ban thiet ke va luot duyet model sheet ma ke hoach da ghim.
     *
     * @return array{0: bool, 1: string}
     */
    public function lock(string $projectId, string $planId, string $approver): array
    {
        $plan = $this->planStage($projectId, $planId);

        if ($plan === null) {
            return [false, 'Không tìm thấy bộ tham chiếu.'];
        }

        $sources = (array) $plan->output_json['sources'];

        return $this->locks->record($projectId, (string) $sources['design_stage_id'], (string) $sources['model_sheet']['review_stage_id'], $approver);
    }

    /** @return array{0: bool, 1: string} */
    public function reject(string $projectId, string $planId, string $imageId): array
    {
        return $this->planStage($projectId, $planId) === null
            ? [false, 'Không tìm thấy bộ tham chiếu.']
            : $this->images->rejectReferencePackItem($projectId, $imageId, $planId);
    }

    /**
     * Kiem tra bo: RULE (file, hash, kich thuoc, dinh dang), do mau, muc san sang; ghi manifest ung
     * vien thanh stage REFERENCE_PACK (cung noi dung thi dung lai).
     *
     * @return array{0: ?VideoPlanningStage, 1: string}
     */
    public function check(string $projectId, string $planId): array
    {
        $plan = $this->planStage($projectId, $planId);

        if ($plan === null) {
            return [null, 'Không tìm thấy bộ tham chiếu.'];
        }

        if (($problem = self::contractProblem($plan)) !== null) {
            return [null, $problem];
        }

        $manifest = $this->manifest($projectId, $plan, true);
        $errors = Validator::schemaErrors($manifest);

        if ($errors !== []) {
            return [null, 'Manifest không đạt schema ReferencePack: '.implode('; ', array_slice($errors, 0, 5))];
        }

        [$stage, $token, $reason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::REFERENCE_PACK, [
            'execution' => self::PACK_EXECUTION,
            'plan_id' => $planId,
            'manifest_sha256' => $manifest['manifest_sha256'],
        ]);

        if ($token === null) {
            return $reason === 'already_succeeded' && $stage !== null ? [$stage, 'existing'] : [null, 'Không ghi được manifest ('.$reason.').'];
        }

        return $this->stageStore->finishSucceeded((string) $stage->id, $token, '', ['ref_pack' => $manifest])
            ? [VideoPlanningStage::query()->find($stage->id), 'checked']
            : [null, 'Lượt kiểm bộ bị giành mất khi lưu — tải lại trang.'];
    }

    /**
     * Duyet va dung bo nay: manifest bat bien, ngoai that san sang, file con nguyen; ghi luot duyet
     * (quyet dinh thu cong co nguoi duyet) va lua chon pack trong metadata_json voi kiem phien ban.
     *
     * @return array{0: bool, 1: string}
     */
    public function approve(string $projectId, string $packStageId, int $expectedVersion, string $actor): array
    {
        $pack = VideoPlanningStage::query()->whereKey($packStageId)->where('project_id', $projectId)
            ->where('stage', PlanningStageName::REFERENCE_PACK->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->first();
        $manifest = is_array($pack?->output_json['ref_pack'] ?? null) ? $pack->output_json['ref_pack'] : null;

        if ($manifest === null) {
            return [false, 'Không tìm thấy manifest bộ tham chiếu.'];
        }

        if (($manifest['readiness']['exterior']['status'] ?? null) !== 'ready' || ($manifest['validation']['rule'] ?? null) !== 'pass') {
            return [false, 'Bộ chưa sẵn sàng cho ngoại thất — còn: '.implode(', ', (array) ($manifest['readiness']['exterior']['blocking'] ?? [])).'.'];
        }

        $plan = $this->planStage($projectId, (string) ($pack->input_json['plan_id'] ?? ''));

        if ($plan !== null && ($problem = self::contractProblem($plan)) !== null) {
            return [false, $problem];
        }

        if ($plan === null || $this->manifest($projectId, $plan, true)['manifest_sha256'] !== $manifest['manifest_sha256']) {
            return [false, 'Nội dung bộ đã đổi so với lần kiểm (ảnh mới, ảnh bị từ chối hoặc file hỏng) — bấm "Kiểm tra bộ" lại.'];
        }

        $sources = $manifest['sources'];
        $lock = $this->locks->status($projectId, (string) $sources['design_stage_id'], (string) $sources['model_sheet']['review_stage_id'], true);

        return DB::transaction(function () use ($projectId, $pack, $manifest, $expectedVersion, $actor, $lock): array {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $metadata = is_array($project->metadata_json) ? $project->metadata_json : [];
            $selection = is_array($metadata[self::SELECTION_KEY] ?? null) ? $metadata[self::SELECTION_KEY] : [];

            if ((int) ($selection['version'] ?? 0) !== $expectedVersion) {
                return [false, 'Lựa chọn bộ tham chiếu đã đổi ở tab khác — tải lại trang trước khi duyệt.'];
            }

            [$review, $token, $reason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::REFERENCE_PACK_REVIEW, [
                'execution' => self::REVIEW_EXECUTION,
                'pack_stage_id' => (string) $pack->id,
                'manifest_sha256' => $manifest['manifest_sha256'],
            ]);

            if ($token === null && ($reason !== 'already_succeeded' || $review === null)) {
                return [false, 'Không ghi được lượt duyệt ('.$reason.').'];
            }

            $accepted = Validator::acceptedEntries($manifest);

            if ($token !== null && ! $this->stageStore->finishSucceeded((string) $review->id, $token, '', [
                'decision' => 'approve',
                'decision_type' => 'manual',
                'decided_by' => $actor,
                'decided_at' => now()->toIso8601String(),
                'policy_version' => Validator::POLICY_VERSION,
                'manifest_sha256' => $manifest['manifest_sha256'],
                'accepted' => $accepted,
                'vision' => $manifest['validation']['vision'],
                'design_lock' => ['locked' => $lock['locked'], 'lock_stage_id' => $lock['lock_stage_id'], 'missing' => $lock['missing']],
            ])) {
                return [false, 'Lượt duyệt bị giành mất khi lưu — tải lại trang.'];
            }

            $history = is_array($selection['history'] ?? null) ? $selection['history'] : [];
            $history[] = ['version' => $expectedVersion + 1, 'pack_stage_id' => (string) $pack->id, 'review_stage_id' => (string) $review->id, 'actor' => $actor, 'at' => now()->toIso8601String()];
            $metadata[self::SELECTION_KEY] = [
                'version' => $expectedVersion + 1,
                'pack_stage_id' => (string) $pack->id,
                'review_stage_id' => (string) $review->id,
                'plan_id' => (string) $pack->input_json['plan_id'],
                'manifest_sha256' => $manifest['manifest_sha256'],
                'locked' => $lock['locked'],
                'selected_at' => now()->toIso8601String(),
                'history' => $history,
            ];
            $project->forceFill(['metadata_json' => $metadata])->save();

            return [true, $lock['locked'] ? 'approved' : 'approved_unlocked'];
        });
    }

    /**
     * Pack dang dung (lua chon da duyet) kem quyet dinh chap nhan, kiem lai hieu luc truoc khi giao
     * cho noi dung: lien ket selection → pack → luot duyet; hash thuc cua manifest; ke hoach con va
     * dung nguon; chuoi nguon (master, thiet ke, luot duyet model sheet); tung artifact da duyet dung
     * ban ghi bat bien trong manifest (khong lay "anh moi nhat" cua ke hoach), con file khop hash, o
     * anh chua bi tu choi / thay sau khi duyet. `$deep` doc lai bytes; mo trang dung kiem nong.
     * Khong co lua chon thi null; co ma hong thi `valid` = false kem ly do.
     *
     * @return ?array{valid: bool, problems: list<string>, pack_stage_id: string, review_stage_id: ?string, plan_id: string, manifest: ?array<string, mixed>, accepted: list<array<string, mixed>>, locked: bool}
     */
    public function selectedPack(string $projectId, bool $deep = true): ?array
    {
        $selection = VideoProject::query()->find($projectId)?->metadata_json[self::SELECTION_KEY] ?? null;

        if (! is_array($selection)) {
            return null;
        }

        $pack = $this->succeeded($projectId, (string) ($selection['pack_stage_id'] ?? ''), PlanningStageName::REFERENCE_PACK);
        $review = $this->succeeded($projectId, (string) ($selection['review_stage_id'] ?? ''), PlanningStageName::REFERENCE_PACK_REVIEW);
        $manifest = is_array($pack?->output_json['ref_pack'] ?? null) ? $pack->output_json['ref_pack'] : null;
        $out = ['valid' => false, 'problems' => [], 'pack_stage_id' => (string) ($selection['pack_stage_id'] ?? ''), 'review_stage_id' => $review?->id === null ? null : (string) $review->id,
            'plan_id' => (string) ($selection['plan_id'] ?? ''), 'manifest' => null, 'accepted' => [], 'locked' => false];

        if ($manifest === null || $review === null || ($review->input_json['pack_stage_id'] ?? null) !== (string) $pack->id
            || ($review->output_json['decision'] ?? null) !== 'approve' || ($review->output_json['decision_type'] ?? null) !== 'manual'
            || ($review->output_json['manifest_sha256'] ?? null) !== $manifest['manifest_sha256'] || ($selection['manifest_sha256'] ?? null) !== $manifest['manifest_sha256']
            || ($selection['plan_id'] ?? null) !== ($pack->input_json['plan_id'] ?? false)) {
            return ['problems' => ['Lựa chọn đã ghi không còn khớp lượt duyệt hoặc manifest.']] + $out;
        }

        $accepted = array_values((array) ($review->output_json['accepted'] ?? []));
        $problems = [];

        if (CanonicalJson::hash($manifest, ['manifest_sha256']) !== $manifest['manifest_sha256']) {
            $problems[] = 'Manifest đã duyệt bị sửa sau khi duyệt (hash không khớp).';
        }

        $plan = $this->planStage($projectId, (string) $pack->input_json['plan_id']);
        $sources = (array) $manifest['sources'];

        if ($plan === null) {
            $problems[] = 'Kế hoạch của bộ đã duyệt không còn.';
        } elseif (($contract = self::contractProblem($plan)) !== null) {
            $problems[] = $contract;
        } elseif (CanonicalJson::hash((array) $plan->output_json['sources']) !== CanonicalJson::hash($sources)) {
            $problems[] = 'Nguồn của manifest khác nguồn đã ghim trong kế hoạch.';
        }

        $problems = [...$problems, ...$this->chainProblems($projectId, $sources, $deep), ...$this->acceptedProblems($projectId, $manifest, $accepted, (string) $pack->input_json['plan_id'], $deep)];

        return [
            'valid' => $problems === [],
            'problems' => array_values(array_unique($problems)),
            'manifest' => $manifest,
            'accepted' => $accepted,
            'locked' => $this->locks->status($projectId, (string) $sources['design_stage_id'], (string) $sources['model_sheet']['review_stage_id'], $deep)['locked'],
        ] + $out;
    }

    /**
     * Quyet dinh chap nhan con dung khong: trung danh sach rut tu manifest da duyet; moi artifact
     * thuoc du an, sha256 khong doi, file con (va bytes khop khi kiem sau); anh render chua bi tu
     * choi / thay, dung ke hoach; goc MS dung o trong snapshot.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<array<string, mixed>>  $accepted
     * @return list<string>
     */
    private function acceptedProblems(string $projectId, array $manifest, array $accepted, string $planId, bool $deep): array
    {
        $key = static fn (array $a): string => ($a['item_id'] ?? '').'|'.($a['artifact_id'] ?? '').'|'.($a['sha256'] ?? '');

        if ($accepted === [] || array_map($key, $accepted) !== array_map($key, Validator::acceptedEntries($manifest))) {
            return ['Danh sách đã duyệt không khớp manifest đã duyệt.'];
        }

        $crops = array_column((array) $manifest['sources']['model_sheet']['crops'], 'sha256', 'artifact_id');
        $problems = [];

        foreach ($accepted as $decision) {
            $artifact = VideoArtifact::query()->whereKey($decision['artifact_id'])->where('project_id', $projectId)->first();
            $item = (string) $decision['item_id'];

            if ($artifact === null) {
                $problems[] = "{$item}: artifact đã duyệt không còn.";

                continue;
            }

            if ((string) $artifact->sha256 !== (string) $decision['sha256']) {
                $problems[] = "{$item}: artifact đã đổi sha256 so với lúc duyệt.";
            } elseif ($deep ? ! self::fileMatches($artifact) : ! Storage::disk((string) $artifact->storage_disk)->exists((string) $artifact->storage_path)) {
                $problems[] = "{$item}: file đã duyệt không còn hoặc không khớp sha256.";
            }

            if (str_starts_with($item, 'view.') && ($crops[(string) $artifact->id] ?? null) !== $decision['sha256']) {
                $problems[] = "{$item}: không phải ô MS trong snapshot.";
            }

            if ($artifact->design_image_id !== null) {
                $image = VideoDesignImage::query()->whereKey($artifact->design_image_id)->where('project_id', $projectId)->first();

                if ($image === null || ! in_array($image->status, [DesignImageStatus::RENDERED->value, DesignImageStatus::APPROVED->value], true)
                    || ($image->prompt_spec_json['plan_id'] ?? null) !== $planId || ($image->prompt_spec_json['item_id'] ?? null) !== $item) {
                    $problems[] = "{$item}: ảnh đã bị từ chối hoặc thay sau khi duyệt bộ.";
                }
            }
        }

        return $problems;
    }

    /**
     * Chon reference cho mot luot goi tu pack dang dung. `$requireLock`: phase can nguon da khoa chi
     * nhan pack co DESIGN LOCK con hieu luc.
     *
     * @param  array<string, mixed>  $request  xem ReferencePackSelector::select()
     * @return array<string, mixed>
     */
    public function selectFor(string $projectId, array $request, bool $requireLock = false): array
    {
        $fail = static fn (string $error): array => ['ok' => false, 'error' => $error, 'policy' => null, 'edit_input' => null, 'references' => []];

        // Chua co duong xac minh nguon dung trang thai thi cong (artifact, hash, bang chung chap nhan,
        // build state) — khong tin nguon do ben goi dua vao.
        if (! ($request['finished'] ?? false)) {
            return $fail('Shot thi công chưa hỗ trợ: chưa có đường xác minh ảnh nguồn đúng trạng thái thi công.');
        }

        unset($request['state_source']);
        $selected = $this->selectedPack($projectId);

        if ($selected === null) {
            return $fail('Dự án chưa chọn bộ tham chiếu đã duyệt.');
        }

        if (! $selected['valid']) {
            return ['pack_stage_id' => $selected['pack_stage_id']] + $fail('Bộ tham chiếu đang dùng không còn hợp lệ — ngừng sử dụng: '.implode(' ', array_slice($selected['problems'], 0, 5)));
        }

        if ($requireLock && ! $selected['locked']) {
            return $fail('Bước này cần thiết kế có DESIGN LOCK còn hiệu lực.');
        }

        return ['pack_stage_id' => $selected['pack_stage_id']] + ReferencePackSelector::select($selected['manifest'], $selected['accepted'], $request);
    }

    /**
     * Panel Phase 3R — chi doc: khong tao stage, khong render, khong doi pack. Tach "pack dang dung"
     * (lua chon da duyet) khoi "ke hoach dang xem" (mac dinh moi nhat, hoac mo theo `$planId`).
     *
     * @return array<string, mixed>
     */
    public function panel(string $projectId, ?string $planId = null): array
    {
        $project = VideoProject::query()->find($projectId);
        $selection = is_array($project?->metadata_json[self::SELECTION_KEY] ?? null) ? $project->metadata_json[self::SELECTION_KEY] : [];
        $selected = $selection === [] ? null : $this->selectedPack($projectId, false);
        $latest = VideoPlanningStage::query()->where('project_id', $projectId)->where('stage', PlanningStageName::REFERENCE_PACK_PLAN->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->orderByDesc('planning_revision')->first();
        $base = ['review_stage_id' => null, 'source_problem' => null, 'plan' => null, 'groups' => [], 'readiness' => null, 'pack' => null,
            'selection' => $selection === [] ? null : $selection, 'selection_version' => (int) ($selection['version'] ?? 0), 'lock' => null,
            'latest_plan_id' => $latest?->id === null ? null : (string) $latest->id,
            'selected' => $selection === [] ? null : [
                'valid' => $selected['valid'] ?? false,
                'problems' => $selected['problems'] ?? [],
                'pack_stage_id' => (string) ($selection['pack_stage_id'] ?? ''),
                'plan_id' => (string) ($selection['plan_id'] ?? ''),
                'plan_revision' => $this->planStage($projectId, (string) ($selection['plan_id'] ?? ''))?->planning_revision,
                'selected_at' => $selection['selected_at'] ?? null,
                'locked' => $selected['locked'] ?? false,
                'readiness' => $selected['manifest']['readiness'] ?? null,
                'accepted_count' => count((array) ($selected['accepted'] ?? [])),
            ],
            'models' => $this->models(), 'qualities' => array_map(static fn (ImageQuality $q): array => ['value' => $q->value, 'label' => $q->label(), 'usd' => $q->estimatedCostUsd()], ImageQuality::cases()),
            'max_render_items' => self::MAX_RENDER_ITEMS];

        try {
            $base['review_stage_id'] = $this->modelSheet->approvedSheet($projectId, false)['review_stage_id'];
        } catch (RuntimeException $e) {
            $base['source_problem'] = $e->getMessage();
        }

        $plan = ($planId === null ? null : $this->planStage($projectId, $planId)) ?? $latest;

        if ($plan === null) {
            return $base;
        }

        $out = (array) $plan->output_json;
        $manifest = $this->manifest($projectId, $plan, false);
        $images = $this->latestImages($projectId, (string) $plan->id);
        $pack = VideoPlanningStage::query()->where('project_id', $projectId)->where('stage', PlanningStageName::REFERENCE_PACK->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.plan_id')) = ?", [(string) $plan->id])->orderByDesc('planning_revision')->first();
        $packManifest = is_array($pack?->output_json['ref_pack'] ?? null) ? $pack->output_json['ref_pack'] : null;
        $groups = [];

        foreach ((array) $out['items'] as $item) {
            $entry = $this->entryOf($manifest, $item);
            $image = $images[$item['id']] ?? null;
            $groups[$item['group']][] = [
                'id' => $item['id'],
                'subject' => $item['subject'],
                'requirement' => $item['requirement'],
                'method' => $item['method'],
                'reason' => $item['reason'],
                'status' => $entry['status'] ?? null,
                'url' => is_string($entry['artifact_id'] ?? null) ? route('video-artifacts.show', $entry['artifact_id']) : null,
                'image_id' => $image?->id,
                'image_status' => $image?->status,
                'render_error' => $image?->render_error,
                'inputs' => array_column((array) ($item['inputs'] ?? []), 'view'),
                'renderable' => $item['method'] === Compiler::METHOD_RENDER && ($image === null || in_array($image->status, [DesignImageStatus::FAILED->value, DesignImageStatus::REJECTED->value, DesignImageStatus::CANDIDATE->value, DesignImageStatus::SUPERSEDED->value], true)),
            ];
        }

        $sources = $out['sources'];
        $base['lock'] = $this->locks->status($projectId, (string) $sources['design_stage_id'], (string) $sources['model_sheet']['review_stage_id']);

        return [
            'plan' => [
                'id' => (string) $plan->id,
                'planning_revision' => (int) $plan->planning_revision,
                'created_at' => $plan->created_at,
                'sources' => $sources,
                'stale_source' => $base['review_stage_id'] !== null && $base['review_stage_id'] !== $sources['model_sheet']['review_stage_id'],
                'contract_problem' => self::contractProblem($plan),
                'is_latest' => (string) $plan->id === $base['latest_plan_id'],
                'is_selected' => (string) $plan->id === ($base['selected']['plan_id'] ?? null),
            ],
            'groups' => $groups,
            'readiness' => $manifest['readiness'],
            'validation' => $manifest['validation'],
            'pack' => $pack === null ? null : [
                'id' => (string) $pack->id,
                'manifest_sha256' => $packManifest['manifest_sha256'] ?? null,
                'readiness' => $packManifest['readiness'] ?? null,
                'validation' => $packManifest['validation'] ?? null,
                // So dau van noi dung (khong gom evidence kiem sau) — manifest_sha256 cua lan kiem sau
                // khong so duoc voi manifest doc nhanh khi mo trang.
                'current' => ($packManifest['content_sha256'] ?? null) === $manifest['content_sha256'],
                'selected' => ($selection['pack_stage_id'] ?? null) === (string) $pack->id,
            ],
        ] + $base;
    }

    /**
     * Snapshot nguon tu luot duyet model sheet: truy nguoc master, artifact, 8 crop, bo, thiet ke.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, string>}
     */
    private function source(string $projectId, string $reviewStageId): array
    {
        $project = VideoProject::query()->with('article.category')->find($projectId) ?? throw new RuntimeException('Không tìm thấy dự án.');
        $review = $this->succeeded($projectId, $reviewStageId, PlanningStageName::MODEL_SHEET_REVIEW) ?? throw new RuntimeException('Lượt duyệt model sheet không thuộc dự án.');
        $in = (array) $review->input_json;
        $set = $this->succeeded($projectId, (string) ($in['model_sheet_set_id'] ?? ''), PlanningStageName::MODEL_SHEET) ?? throw new RuntimeException('Không tìm thấy bộ model sheet của lượt duyệt.');
        $design = $this->succeeded($projectId, (string) ($in['design_stage_id'] ?? ''), PlanningStageName::YACHT_DESIGN) ?? throw new RuntimeException('Không tìm thấy bản thiết kế của lượt duyệt.');
        $image = VideoDesignImage::query()->whereKey($in['master_image_id'] ?? null)->where('project_id', $projectId)->where('image_type', DesignImageStore::MODEL_SHEET_MASTER_TYPE)->first();
        $master = VideoArtifact::query()->whereKey($in['master_artifact_id'] ?? null)->where('project_id', $projectId)->first();

        if ($image === null || $master === null || $image->status !== DesignImageStatus::APPROVED->value || (string) $image->selected_artifact_id !== (string) $master->id
            || (string) $master->sha256 !== (string) ($in['master_artifact_sha256'] ?? '') || ! self::fileMatches($master)) {
            throw new RuntimeException('Master của lượt duyệt không còn ở trạng thái đã duyệt với đúng ảnh, hash và file.');
        }

        if (($design->output_json['check']['verdict'] ?? null) !== 'PASS' || self::canonicalProblem($design, (string) ($in['design_canonical_hash'] ?? '')) !== null) {
            throw new RuntimeException('Bản thiết kế không đạt kiểm hoặc canonical không khớp lượt duyệt model sheet.');
        }

        $crops = array_values((array) ($review->output_json['crops'] ?? []));

        if (array_column($crops, 'view_id') !== Compiler::VIEW_IDS) {
            throw new RuntimeException('Lượt duyệt không có đủ 8 ô MS1–MS8 theo thứ tự.');
        }

        foreach ($crops as $crop) {
            $artifact = VideoArtifact::query()->whereKey($crop['artifact_id'] ?? null)->where('project_id', $projectId)->first();

            if ($artifact === null || (string) $artifact->sha256 !== (string) ($crop['sha256'] ?? '') || ! self::fileMatches($artifact)) {
                throw new RuntimeException("Ô {$crop['view_id']} của lượt duyệt không còn file hoặc không khớp sha256.");
            }
        }

        $phase = AiPhase::query()->where('category_id', $project->article?->category?->id)->where('workflow_code', (string) config('video.superyacht.workflow_code'))
            ->where('phase_code', self::PHASE_CODE)->where('is_current', true)->where('status', AiPhase::PUBLISHED)->first()
            ?? throw new RuntimeException('Chưa có prompt '.self::PHASE_CODE.' hiện hành đã phát hành cho chủ đề này.');
        $schema = $this->contractSchema($phase)
            ?? throw new RuntimeException('Prompt '.self::PHASE_CODE.' revision '.$phase->revision.' chưa liên kết schema ReferencePack đúng hợp đồng code đang áp dụng — cần phát hành revision mới kèm schema trước khi lập bộ.');

        $labels = [];

        foreach ((array) ($set->output_json['views'] ?? []) as $view) {
            if (is_array($view) && is_string($view['id'] ?? null)) {
                $labels[$view['id']] = (string) ($view['view'] ?? $view['id']);
            }
        }

        $sources = [
            'project_id' => $projectId,
            'design_stage_id' => (string) $design->id,
            'design_canonical_hash' => (string) $in['design_canonical_hash'],
            'design_raw_sha256' => hash('sha256', (string) $design->raw_response),
            'design_prompt_revision' => is_int($design->input_json['phase_revision'] ?? null) ? $design->input_json['phase_revision'] : null,
            'model_sheet' => [
                'set_id' => (string) $set->id,
                'review_stage_id' => (string) $review->id,
                'master_image_id' => (string) $image->id,
                'master_artifact_id' => (string) $master->id,
                'master_sha256' => (string) $master->sha256,
                'crops' => array_map(static fn (array $c): array => ['view_id' => $c['view_id'], 'artifact_id' => $c['artifact_id'], 'sha256' => $c['sha256'], 'width' => $c['width'] ?? null, 'height' => $c['height'] ?? null], $crops),
            ],
            'phase' => ['code' => self::PHASE_CODE, 'id' => (string) $phase->id, 'revision' => (int) $phase->revision, 'sha256' => (string) $phase->content_sha256,
                'schema_id' => (string) $schema->id, 'schema_version' => (string) $schema->version],
            'compiler_version' => Compiler::VERSION,
            'policy_version' => Validator::POLICY_VERSION,
        ];
        $data = is_array($design->output_json['design']['data'] ?? null) ? $design->output_json['design']['data'] : (JsonAnswer::object((string) $design->raw_response)['data'] ?? []);

        return [$sources, Compiler::facts((array) $data), $labels];
    }

    /**
     * Cong tuong thich: revision prompt phai lien ket (output) schema ReferencePack da phat hanh co
     * noi dung trung hop dong code dang ap dung. Revision cu khong co lien ket thi khong lap bo —
     * khong ghi revision cu nhu the no mo ta hanh vi moi.
     */
    private function contractSchema(AiPhase $phase): ?AiJsonSchema
    {
        $contract = json_decode((string) file_get_contents(resource_path(Validator::SCHEMA_FILE)), true);
        $links = AiPhaseSchema::query()->where('phase_id', $phase->id)->where('role', 'output')->with('schema')->get();

        foreach ($links as $link) {
            $schema = $link->schema;

            if ($schema !== null && $schema->namespace === 'superyacht' && $schema->schema_key === 'ReferencePack' && $schema->status === AiJsonSchema::PUBLISHED
                && is_array($contract) && json_decode((string) $schema->schema_content, true) == $contract) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * Muc ke hoach kem prompt da bien dich va anh nguon da phan giai (artifact + sha) cua muc render.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $sources
     * @return array<string, mixed>
     */
    private function prepare(array $item, array $facts, array $sources): array
    {
        if ($item['method'] !== Compiler::METHOD_RENDER) {
            return $item;
        }

        $crops = array_column($sources['model_sheet']['crops'], null, 'view_id');
        $prompt = Compiler::prompt($item, $facts);

        return $item + [
            'prompt' => $prompt,
            'prompt_sha256' => hash('sha256', $prompt),
            'resolved_inputs' => array_values(array_map(static fn (array $input, int $position): array => [
                'position' => $position,
                'role' => $input['role'],
                'view' => $input['view'],
                'artifact_id' => $crops[$input['view']]['artifact_id'],
                'sha256' => $crops[$input['view']]['sha256'],
            ], $item['inputs'], array_keys($item['inputs']))),
        ];
    }

    /**
     * Manifest hien tai cua ke hoach tu ket qua da co. `$deep` doc lai file va so sha256 / do mau.
     *
     * @return array<string, mixed>
     */
    private function manifest(string $projectId, VideoPlanningStage $plan, bool $deep): array
    {
        $out = (array) $plan->output_json;
        $items = (array) $out['items'];
        $images = $this->latestImages($projectId, (string) $plan->id);
        $chain = $this->chainProblems($projectId, (array) $out['sources'], $deep);
        $results = [];

        foreach ($items as $item) {
            $result = match ($item['method']) {
                Compiler::METHOD_REUSE => $this->cropResult($projectId, $out['sources'], $item, $chain, $deep),
                Compiler::METHOD_CODE => $this->codeResult($projectId, (string) $plan->id, $item, $deep),
                Compiler::METHOD_RENDER => $this->renderResult($images[$item['id']] ?? null, $item, $deep),
                default => null,
            };

            if ($result !== null) {
                $results[$item['id']] = $result;
            }
        }

        return Validator::manifest($out['sources'], $items, $results);
    }

    /**
     * Chuoi nguon cua snapshot con nguyen khong: luot duyet model sheet van thanh cong va tro dung bo,
     * thiet ke, master, 8 o nhu luc lap; master van duoc duyet voi dung artifact va hash; thiet ke van
     * dat kiem voi canonical do (hash tinh lai tu noi dung, raw khong doi). Gay chuoi thi cac goc MS
     * khong con "ke thua duyet". `$deep` doc lai bytes master; kiem nong chi xem file con.
     *
     * @param  array<string, mixed>  $sources
     * @return list<string>
     */
    private function chainProblems(string $projectId, array $sources, bool $deep): array
    {
        $ms = (array) $sources['model_sheet'];
        $review = $this->succeeded($projectId, (string) $ms['review_stage_id'], PlanningStageName::MODEL_SHEET_REVIEW);

        if ($review === null) {
            return ['Lượt duyệt model sheet của snapshot không còn.'];
        }

        $in = (array) $review->input_json;
        $problems = [];

        if (($in['model_sheet_set_id'] ?? null) !== $ms['set_id'] || ($in['design_stage_id'] ?? null) !== $sources['design_stage_id']
            || ($in['design_canonical_hash'] ?? null) !== $sources['design_canonical_hash'] || ($in['master_image_id'] ?? null) !== $ms['master_image_id']
            || ($in['master_artifact_id'] ?? null) !== $ms['master_artifact_id'] || ($in['master_artifact_sha256'] ?? null) !== $ms['master_sha256']) {
            $problems[] = 'Lượt duyệt model sheet không còn trỏ đúng bộ, thiết kế và master của snapshot.';
        }

        $pick = static fn (array $crops): array => array_map(static fn (array $c): array => [$c['view_id'] ?? null, $c['artifact_id'] ?? null, $c['sha256'] ?? null], array_values($crops));

        if ($pick((array) ($review->output_json['crops'] ?? [])) !== $pick((array) $ms['crops'])) {
            $problems[] = '8 ô của lượt duyệt model sheet khác 8 ô trong snapshot.';
        }

        if ($this->succeeded($projectId, (string) $ms['set_id'], PlanningStageName::MODEL_SHEET) === null) {
            $problems[] = 'Bộ model sheet của snapshot không còn.';
        }

        $design = $this->succeeded($projectId, (string) $sources['design_stage_id'], PlanningStageName::YACHT_DESIGN);

        if ($design === null || ($design->output_json['check']['verdict'] ?? null) !== 'PASS' || self::canonicalProblem($design, (string) $sources['design_canonical_hash']) !== null) {
            $problems[] = 'Bản thiết kế của snapshot không còn đạt kiểm với đúng canonical.';
        } elseif (hash('sha256', (string) $design->raw_response) !== ($sources['design_raw_sha256'] ?? null)) {
            $problems[] = 'Nội dung gốc của bản thiết kế đã đổi so với snapshot.';
        }

        $image = VideoDesignImage::query()->whereKey($ms['master_image_id'])->where('project_id', $projectId)->first();
        $master = VideoArtifact::query()->whereKey($ms['master_artifact_id'])->where('project_id', $projectId)->first();

        if ($image === null || $master === null || $image->status !== DesignImageStatus::APPROVED->value
            || (string) $image->selected_artifact_id !== (string) $master->id || (string) $master->sha256 !== (string) $ms['master_sha256']) {
            $problems[] = 'Master của snapshot không còn được duyệt với đúng ảnh và hash.';
        } elseif ($deep ? ! self::fileMatches($master) : ! Storage::disk((string) $master->storage_disk)->exists((string) $master->storage_path)) {
            $problems[] = 'File master của snapshot không còn hoặc không khớp sha256.';
        }

        return $problems;
    }

    /**
     * Goc MS dung lai: sha256 cua snapshot = sha256 artifact = sha256 bytes (khi kiem sau), va chuoi
     * nguon con nguyen. Chi khi do moi ke thua duyet tu luot duyet model sheet.
     *
     * @param  list<string>  $chain
     * @return ?array<string, mixed>
     */
    private function cropResult(string $projectId, array $sources, array $item, array $chain, bool $deep): ?array
    {
        $crop = array_column($sources['model_sheet']['crops'], null, 'view_id')[$item['view']] ?? null;
        $artifact = $crop === null ? null : VideoArtifact::query()->whereKey($crop['artifact_id'])->where('project_id', $projectId)->first();

        if ($artifact === null) {
            return null;
        }

        $problems = $chain;

        if ((string) $artifact->sha256 !== (string) $crop['sha256']) {
            $problems[] = "Artifact của {$item['view']} có sha256 khác snapshot.";
        }

        return self::artifactResult($artifact, 'reuse', $deep) + [
            'acceptance' => $problems === [] ? 'inherited' : 'pending',
            'problems' => $problems,
            'evidence' => ['model_sheet_review_stage_id' => $sources['model_sheet']['review_stage_id'], 'snapshot_sha256' => $crop['sha256']],
        ];
    }

    /** @return ?array<string, mixed> */
    private function codeResult(string $projectId, string $planId, array $item, bool $deep): ?array
    {
        $artifact = VideoArtifact::query()->where('project_id', $projectId)->where('role', DesignImageStore::REFERENCE_PACK_CODE_ROLE)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.plan_id')) = ?", [$planId])
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.item_id')) = ?", [$item['id']])
            ->orderByDesc('created_at')->first();

        if ($artifact === null) {
            return null;
        }

        $result = self::artifactResult($artifact, 'code', $deep) + ['acceptance' => 'pending', 'evidence' => ['generator' => $artifact->metadata_json['generator'] ?? null]];

        if ($deep && $result['file_ok'] && $item['group'] === 'colors') {
            $measured = ReferenceColorSheet::measure((string) Storage::disk((string) $artifact->storage_disk)->get((string) $artifact->storage_path), $item['colors']);
            $result['problems'] = array_values(array_map(static fn (array $m): string => "Ô {$m['role']} đo {$m['measured']} lệch {$m['hex']} {$m['max_channel_delta']} đơn vị.",
                array_filter($measured, static fn (array $m): bool => $m['max_channel_delta'] > 2)));
            $result['evidence']['measured'] = $measured;
        }

        return $result;
    }

    /**
     * Anh render: phai truy duoc ve dung ke hoach, prompt va anh nguon cua muc (khong chi co file).
     *
     * @return ?array<string, mixed>
     */
    private function renderResult(?VideoDesignImage $image, array $item, bool $deep): ?array
    {
        if ($image === null) {
            return null;
        }

        $artifact = $image->status === DesignImageStatus::REJECTED->value ? null
            : ($image->selected_artifact_id !== null ? $image->artifacts->firstWhere('id', $image->selected_artifact_id) : $image->artifacts->first());

        if ($artifact === null || ! in_array($image->status, [DesignImageStatus::RENDERED->value, DesignImageStatus::APPROVED->value], true)) {
            return ['generation' => $image->status, 'error' => $image->render_error];
        }

        $spec = (array) $image->prompt_spec_json;
        $problems = ($spec['prompt_sha256'] ?? null) === ($item['prompt_sha256'] ?? false)
            && ($spec['sources_sha256'] ?? null) === hash('sha256', (string) json_encode($item['resolved_inputs'] ?? null))
            && ($spec['sources'] ?? null) == ($item['resolved_inputs'] ?? false) // cot JSON co the doi thu tu khoa
            ? [] : ['Ảnh không truy được về đúng prompt và ảnh nguồn của mục trong kế hoạch.'];

        return self::artifactResult($artifact, 'render', $deep) + [
            'acceptance' => 'pending',
            'problems' => $problems,
            'evidence' => ['design_image_id' => (string) $image->id, 'prompt_sha256' => $image->prompt_spec_json['prompt_sha256'] ?? null, 'model' => $image->prompt_spec_json['model'] ?? null],
        ];
    }

    /** @return array<string, mixed> */
    private static function artifactResult(VideoArtifact $artifact, string $origin, bool $deep): array
    {
        return [
            'artifact_id' => (string) $artifact->id,
            'sha256' => (string) $artifact->sha256,
            'mime' => (string) $artifact->mime_type,
            'width' => $artifact->width === null ? null : (int) $artifact->width,
            'height' => $artifact->height === null ? null : (int) $artifact->height,
            'file_ok' => $deep ? self::fileMatches($artifact) : Storage::disk((string) $artifact->storage_disk)->exists((string) $artifact->storage_path),
            'origin' => $origin,
            'vision' => 'unchecked',
        ];
    }

    /**
     * Canonical da luu phai mang hash mong doi VA trung noi dung canonical trong raw (raw duoc khoa
     * bang design_raw_sha256). Khong tinh lai hash tu ban luu: cot JSON doi 1.0 thanh 1 nen hash tinh
     * lai lech o thiet ke hop le; so noi dung voi raw sau khi bo phan lam giau (hash, compiled_text)
     * va chuan hoa so nguyen dang thuc.
     */
    private static function canonicalProblem(VideoPlanningStage $design, string $expected): ?string
    {
        $stored = $design->output_json['design']['data']['canonical'] ?? null;
        $raw = JsonAnswer::object((string) $design->raw_response)['data']['canonical'] ?? null;

        if (! is_array($stored) || ($stored['hash'] ?? null) !== $expected) {
            return 'canonical khác hash mong đợi';
        }

        $content = static function (array $canonical): string {
            unset($canonical['hash']);

            foreach ((array) ($canonical['build_states'] ?? []) as $i => $state) {
                unset($canonical['build_states'][$i]['compiled_text']);
            }

            $norm = static function (mixed $v) use (&$norm): mixed {
                return is_array($v) ? array_map($norm, $v) : (is_float($v) && floor($v) === $v && abs($v) < 1e15 ? (int) $v : $v);
            };

            return CanonicalJson::encode($norm($canonical));
        };

        return is_array($raw) && $content($stored) === $content($raw) ? null : 'nội dung canonical đã lưu khác canonical trong raw';
    }

    private static function fileMatches(VideoArtifact $artifact): bool
    {
        $disk = Storage::disk((string) $artifact->storage_disk);
        $bytes = $disk->exists((string) $artifact->storage_path) ? $disk->get((string) $artifact->storage_path) : null;

        return is_string($bytes) && hash_equals((string) $artifact->sha256, hash('sha256', $bytes));
    }

    /** @return array<string, VideoDesignImage> item_id => o moi nhat */
    private function latestImages(string $projectId, string $planId): array
    {
        $latest = [];

        foreach ($this->images->referencePackItemsFor($projectId, $planId) as $image) {
            $latest[(string) ($image->prompt_spec_json['item_id'] ?? '')] ??= $image;
        }

        return $latest;
    }

    /** @return ?array<string, mixed> */
    private function entryOf(array $manifest, array $item): ?array
    {
        $list = match ($item['group']) {
            'views', 'details' => (array) $manifest[$item['group']],
            'colors', 'turntable' => array_filter([$manifest[$item['group']]]),
            'rooms' => array_filter([((array) $manifest['rooms'])[$item['room']][$item['kind']] ?? null]),
            default => [],
        };

        return array_values(array_filter($list, static fn (array $e): bool => $e['id'] === $item['id']))[0] ?? null;
    }

    /**
     * Ke hoach lap truoc cong tuong thich (snapshot khong ghim schema hop dong) thi chi xem, khong
     * tao / kiem / duyet tiep — revision cu khong mo ta hanh vi hien tai.
     */
    private static function contractProblem(VideoPlanningStage $plan): ?string
    {
        $phase = (array) ($plan->output_json['sources']['phase'] ?? []);

        return is_string($phase['schema_id'] ?? null) && $phase['schema_id'] !== '' ? null
            : 'Bộ này lập theo prompt '.self::PHASE_CODE.' rev '.($phase['revision'] ?? '?').' chưa có hợp đồng schema ReferencePack — chỉ xem; lập bộ mới sau khi phát hành revision có schema.';
    }

    private function planStage(string $projectId, string $planId): ?VideoPlanningStage
    {
        return $this->succeeded($projectId, $planId, PlanningStageName::REFERENCE_PACK_PLAN);
    }

    private function succeeded(string $projectId, string $stageId, PlanningStageName $name): ?VideoPlanningStage
    {
        return $stageId === '' ? null : VideoPlanningStage::query()->whereKey($stageId)->where('project_id', $projectId)
            ->where('stage', $name->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->first();
    }

    /** @return array<string, mixed> entry registry da kiem model / quality / kich thuoc */
    private function settings(string $model, string $quality): array
    {
        $entry = $this->registry->find(ModelSheetService::TASK, 'openai:'.$model) ?? throw new RuntimeException("Model {$model} không dùng được cho ảnh tham chiếu.");
        $enum = ImageModel::tryFrom($model) ?? throw new RuntimeException("Model {$model} không có trong ImageModel.");
        $q = ImageQuality::tryFrom($quality) ?? throw new RuntimeException("Quality {$quality} không hợp lệ.");

        if (! $enum->supports($q)) {
            throw new RuntimeException("Quality {$quality} không dùng được với {$entry['label']}.");
        }

        foreach (['1536x1024', '1024x1024'] as $size) {
            if (! in_array($size, (array) ($entry['controls']['sizes'] ?? []), true)) {
                throw new RuntimeException("Size {$size} không dùng được với {$entry['label']}.");
            }
        }

        return $entry;
    }

    /** @return list<array{value: string, label: string, default: bool}> */
    private function models(): array
    {
        return array_map(static fn (array $e): array => ['value' => (string) $e['model'], 'label' => (string) $e['label'], 'default' => ($e['default'] ?? false) === true],
            $this->registry->forTask(ModelSheetService::TASK));
    }
}
