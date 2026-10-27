<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Video\Llm\JsonAnswer;
use App\Video\Superyacht\Support\CanonicalJson;
use App\Video\Superyacht\Validation\DocumentValidator;
use App\Video\Superyacht\Validation\Invariants\ArrayInvariantContext;
use App\Video\YachtDesign\YachtDesignCheck;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * DESIGN LOCK (spec Phase 3, Step 11): canonical revision >= 1 da khoa, constraint registry, anchors
 * va model sheet da duyet (master + 8 o) khoa chung trong mot ban ghi LockRecord. Khong bien du lieu
 * draft thanh du lieu khoa bang cach doi trang thai: thieu thanh phan thi bao chinh xac phan thieu,
 * khong ghi. Khong sua noi dung ban thiet ke.
 *
 * Mot ban khoa da ghi chi con hieu luc khi ban ghi dat schema + invariant (INV-LK-01), bundle_hash
 * khop, thanh phan hien tai trung dung thanh phan da khoa va nguon (master, 8 o) con nguyen. Nguon
 * mat hay doi thi khoa vo hieu — khong bao "da khoa" chi vi con stage cu.
 */
final class DesignLockService
{
    public const EXECUTION = 'design-lock-v1';

    public function __construct(private readonly PlanningStageStore $stageStore) {}

    /**
     * `$deep` doc lai bytes master va 8 o de so sha256 (dung khi duyet / dung pack); mac dinh chi
     * kiem file con tren dia.
     *
     * @return array{locked: bool, lock_stage_id: ?string, missing: list<string>, components: list<array<string, mixed>>}
     */
    public function status(string $projectId, string $designStageId, string $reviewStageId, bool $deep = false): array
    {
        [$components, $missing] = $this->components($projectId, $designStageId, $reviewStageId, $deep);
        $stages = VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::DESIGN_LOCK->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.design_stage_id')) = ?", [$designStageId])
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.model_sheet_review_stage_id')) = ?", [$reviewStageId])
            ->orderByDesc('planning_revision')
            ->get();
        $stale = [];

        foreach ($stages as $stage) {
            $problems = $missing === [] ? $this->recordProblems($stage, $components) : ['Thành phần khoá hiện không còn đủ.'];

            if ($problems === []) {
                return ['locked' => true, 'lock_stage_id' => (string) $stage->id, 'missing' => [], 'components' => $components];
            }

            $stale[] = 'Bản khoá '.$stage->id.' không còn hiệu lực: '.implode(' ', $problems);
        }

        return ['locked' => false, 'lock_stage_id' => null, 'missing' => [...array_slice($stale, 0, 1), ...$missing], 'components' => $components];
    }

    /** @return array{0: bool, 1: string} */
    public function record(string $projectId, string $designStageId, string $reviewStageId, string $approver): array
    {
        [$components, $missing] = $this->components($projectId, $designStageId, $reviewStageId, true);

        if ($missing !== []) {
            return [false, 'Chưa khoá được: '.implode(' ', $missing)];
        }

        $doc = [
            'id' => (string) Str::uuid(),
            'project_id' => $projectId,
            'lock_type' => 'design',
            'components' => $components,
            'bundle_hash' => CanonicalJson::bundleHash(array_column($components, 'hash')),
            'approver' => $approver,
            'approved_at' => now()->toIso8601String(),
        ];
        $report = app(DocumentValidator::class)->validate('LockRecord', (string) json_encode($doc), self::context($components));

        if ($report->fails()) {
            return [false, 'Bản ghi khoá không đạt LockRecord: '.implode('; ', $report->messages())];
        }

        [$stage, $token, $reason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::DESIGN_LOCK, [
            'execution' => self::EXECUTION,
            'design_stage_id' => $designStageId,
            'model_sheet_review_stage_id' => $reviewStageId,
            'bundle_hash' => $doc['bundle_hash'],
        ]);

        if ($token === null) {
            return $reason === 'already_succeeded' && $stage !== null && $this->recordProblems($stage, $components) === []
                ? [true, 'already_locked']
                : [false, 'Không ghi được bản khoá ('.$reason.').'];
        }

        return $this->stageStore->finishSucceeded((string) $stage->id, $token, '', ['lock_record' => $doc])
            ? [true, 'locked']
            : [false, 'Lượt khoá bị giành mất khi lưu — tải lại trang.'];
    }

    /**
     * Ban ghi khoa da luu con dung voi thanh phan hien tai khong (schema, INV-LK-01, bundle_hash,
     * trung tap thanh phan).
     *
     * @param  list<array<string, mixed>>  $components  thanh phan hien tai (da kiem nguon)
     * @return list<string>
     */
    private function recordProblems(VideoPlanningStage $stage, array $components): array
    {
        $doc = $stage->output_json['lock_record'] ?? null;

        if (! is_array($doc)) {
            return ['Không có lock_record.'];
        }

        $report = app(DocumentValidator::class)->validate('LockRecord', (string) json_encode($doc), self::context($components));
        $problems = $report->fails() ? $report->messages() : [];

        if (($doc['lock_type'] ?? null) !== 'design' || ($doc['project_id'] ?? null) !== $stage->project_id) {
            $problems[] = 'Bản ghi không phải khoá thiết kế của dự án này.';
        }

        if (($doc['bundle_hash'] ?? null) !== ($stage->input_json['bundle_hash'] ?? null)) {
            $problems[] = 'bundle_hash của bản ghi không khớp lượt khoá.';
        }

        $key = static fn (array $c): string => $c['artifact_type'].'|'.$c['artifact_id'].'|'.$c['revision'].'|'.$c['hash'];
        $locked = array_map($key, (array) ($doc['components'] ?? []));
        $current = array_map($key, $components);
        sort($locked);
        sort($current);

        if ($locked !== $current) {
            $problems[] = 'Thành phần đã khoá khác thành phần hiện tại.';
        }

        return array_values(array_unique(array_map('strval', $problems)));
    }

    /** @param list<array<string, mixed>> $components */
    private static function context(array $components): ArrayInvariantContext
    {
        $artifacts = [];

        foreach ($components as $c) {
            $artifacts[$c['artifact_id'].'@'.$c['revision']] = $c['hash'];
        }

        return new ArrayInvariantContext(['artifacts' => $artifacts]);
    }

    /**
     * Thanh phan khoa va phan con thieu (doc du lieu da luu, khong sua). Nguon model sheet duoc kiem
     * lai: master con duoc duyet dung artifact va hash, du 8 o MS1–MS8 con file khop sha256.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function components(string $projectId, string $designStageId, string $reviewStageId, bool $deep): array
    {
        $design = VideoPlanningStage::query()->whereKey($designStageId)->where('project_id', $projectId)
            ->where('stage', PlanningStageName::YACHT_DESIGN->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->first();
        $review = VideoPlanningStage::query()->whereKey($reviewStageId)->where('project_id', $projectId)
            ->where('stage', PlanningStageName::MODEL_SHEET_REVIEW->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->first();

        if ($design === null || $review === null || ($review->input_json['design_stage_id'] ?? null) !== $designStageId) {
            return [[], ['Bản thiết kế hoặc lượt duyệt model sheet không thuộc dự án hoặc không khớp nhau.']];
        }

        $in = (array) $review->input_json;
        $raw = JsonAnswer::object((string) $design->raw_response) ?? [];
        $data = is_array($design->output_json['design']['data'] ?? null) ? $design->output_json['design']['data'] : [];
        $canonical = is_array($data['canonical'] ?? null) ? $data['canonical'] : [];
        $constraints = is_array($raw['data']['constraints'] ?? null) ? $raw['data']['constraints'] : [];
        $anchors = is_array($data['anchors'] ?? null) ? $data['anchors'] : [];
        $image = VideoDesignImage::query()->whereKey($in['master_image_id'] ?? null)->where('project_id', $projectId)->first();
        $master = VideoArtifact::query()->whereKey($in['master_artifact_id'] ?? null)->where('project_id', $projectId)->first();
        $crops = array_values((array) ($review->output_json['crops'] ?? []));
        $missing = [];

        if (($design->output_json['check']['verdict'] ?? null) !== YachtDesignCheck::PASS) {
            $missing[] = 'Bản thiết kế không còn đạt kiểm.';
        }

        if (($canonical['revision_kind'] ?? null) !== 'locked' || (int) ($canonical['revision'] ?? 0) < 1) {
            $missing[] = 'Canonical đang revision '.json_encode($canonical['revision'] ?? null).' ('.($canonical['revision_kind'] ?? '?').') — cần canonical revision ≥ 1 đã khoá, đối chiếu với MS1/MS6 đã duyệt.';
        }

        if (($canonical['hash'] ?? null) !== ($in['design_canonical_hash'] ?? null)) {
            $missing[] = 'Canonical hiện tại khác canonical của lượt duyệt model sheet.';
        }

        if ($constraints === []) {
            $missing[] = 'Thiếu constraint registry (data.constraints) của bản thiết kế.';
        }

        foreach (['yacht', 'geometry', 'finished_surface'] as $key) {
            if (! is_string($anchors[$key] ?? null) || trim($anchors[$key]) === '') {
                $missing[] = "Thiếu anchor {$key}.";
            }
        }

        if ($image === null || $master === null || $image->status !== DesignImageStatus::APPROVED->value || (string) $image->selected_artifact_id !== (string) $master->id
            || (string) $master->sha256 !== (string) ($in['master_artifact_sha256'] ?? '') || ! self::fileOk($master, $deep)) {
            $missing[] = 'Master model sheet của lượt duyệt không còn được duyệt với đúng ảnh, hash và file.';
        }

        if (array_column($crops, 'view_id') !== ['MS1', 'MS2', 'MS3', 'MS4', 'MS5', 'MS6', 'MS7', 'MS8']) {
            $missing[] = 'Lượt duyệt model sheet không có đủ 8 ô MS1–MS8.';
        } else {
            foreach ($crops as $crop) {
                $artifact = VideoArtifact::query()->whereKey($crop['artifact_id'] ?? null)->where('project_id', $projectId)->first();

                if ($artifact === null || (string) $artifact->sha256 !== (string) ($crop['sha256'] ?? '') || ! self::fileOk($artifact, $deep)) {
                    $missing[] = "Ô {$crop['view_id']} không còn file hoặc không khớp sha256.";
                }
            }
        }

        $hash = static fn (mixed $value): string => CanonicalJson::hash($value);
        $revision = (int) ($canonical['revision'] ?? 0);
        $components = array_values(array_filter([
            is_string($canonical['hash'] ?? null) && CanonicalJson::isValidHash($canonical['hash'])
                ? ['artifact_id' => $designStageId, 'artifact_type' => 'canonical', 'revision' => $revision, 'hash' => $canonical['hash']] : null,
            $constraints === [] ? null : ['artifact_id' => $designStageId.'#constraints', 'artifact_type' => 'constraint_registry', 'revision' => $revision, 'hash' => $hash($constraints)],
            $anchors === [] ? null : ['artifact_id' => $designStageId.'#anchors', 'artifact_type' => 'anchors', 'revision' => $revision, 'hash' => $hash($anchors)],
            $master === null ? null : ['artifact_id' => (string) $master->id, 'artifact_type' => 'model_sheet', 'revision' => 1, 'hash' => 'sha256:'.$master->sha256],
            $crops === [] ? null : ['artifact_id' => $reviewStageId.'#crops', 'artifact_type' => 'model_sheet_crops', 'revision' => 1,
                'hash' => $hash(array_map(static fn (array $c): array => ['view_id' => $c['view_id'] ?? null, 'artifact_id' => $c['artifact_id'] ?? null, 'sha256' => $c['sha256'] ?? null], $crops))],
        ]));

        return [$components, $missing];
    }

    private static function fileOk(VideoArtifact $artifact, bool $deep): bool
    {
        $disk = Storage::disk((string) $artifact->storage_disk);

        if (! $disk->exists((string) $artifact->storage_path)) {
            return false;
        }

        return ! $deep || hash_equals((string) $artifact->sha256, hash('sha256', (string) $disk->get((string) $artifact->storage_path)));
    }
}
