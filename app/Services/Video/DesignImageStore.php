<?php

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Enums\ImageQuality;
use App\Models\VideoArtifact;
use App\Models\VideoCostEntry;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Models\VideoRenderScene;
use App\Video\Media\OpenAiImagePricing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DesignImageStore
{
    private const CODE_PREFIX = 'master_vessel_';

    private const CODE_SUFFIX = '_anchor_v';

    private const CODE_MAX = 100;

    private const IDENTITY_KEYS = ['prompt', 'model', 'quality', 'size', 'variations'];

    public const ANCHOR_TYPE = 'identity_anchor';

    public const REFERENCE_TYPE = 'reference_view';

    public const SCENE_KEYFRAME_TYPE = 'scene_keyframe';

    public const ENVIRONMENT_TYPE = 'environment_plate';

    public function __construct(
        private readonly OpenAiImagePricing $openAiPricing = new OpenAiImagePricing,
    ) {}

    /**
     * Gia duoc dong bang NGAY LUC TAO O, mot lan, cho moi loai anh — sau nay bang gia
     * doi thi o cu van giu con so no da duoc bao truoc khi bam.
     *
     * Provider tu mang gia san (Gemini di qua registry) thi giu nguyen, nhung chi khi
     * mang DU CA HAI: don gia hop le va phien ban bang gia. Nua voi thi coi nhu chua co.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function withPriceSnapshot(array $spec): array
    {
        if (in_array((string) ($spec['pricing'] ?? 'estimated'), ['unpriced', 'free'], true)) {
            return $spec;
        }

        $unit = $spec['unit_cost_usd'] ?? null;
        $version = $spec['pricing_version'] ?? null;

        $carried = (is_int($unit) || is_float($unit))
            && (float) $unit > 0
            && is_finite((float) $unit)
            && is_string($version)
            && trim($version) !== '';

        if ($carried) {
            return $spec;
        }

        $priced = $this->openAiPricing->unitFor(
            (string) ($spec['model'] ?? ''), (string) ($spec['quality'] ?? ''),
        );
        $quality = ImageQuality::tryFrom((string) ($spec['quality'] ?? ''));

        if ($priced === null && $quality !== null && $quality->estimatedCostUsd() === null
            && (string) ($spec['provider'] ?? 'openai') === 'openai') {
            return array_replace($spec, [
                'pricing' => 'unpriced',
                'unit_cost_usd' => null,
                'pricing_version' => null,
            ]);
        }

        return array_replace($spec, [
            'unit_cost_usd' => $priced['usd'] ?? null,
            'pricing_version' => $priced['version'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{0: ?VideoDesignImage, 1: string} [$image, $reason]
     *                                                reason: created|already_exists|project_not_found
     */
    public function createCandidate(string $projectId, string $creator, array $spec): array
    {
        $spec = $this->withPriceSnapshot($spec);
        $sha = $this->identityHash($spec);

        try {
            return DB::transaction(function () use ($projectId, $creator, $spec, $sha) {
                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

                $existing = $this->findByHash($projectId, $sha);

                if ($existing !== null) {
                    // Hash dedup chi bam 5 khoa dinh danh, con `prompt_spec_json`
                    // cho them lineage — thu ma buoc render bat buoc phai co. Mot
                    // o chua sinh ra artifact nao thi chua tieu dong nao, nen spec
                    // cua no van con duoc phep cap nhat theo hop dong moi.
                    if ($existing->artifacts()->doesntExist()) {
                        $existing->update(['prompt_spec_json' => $spec]);
                    }

                    return [$existing->refresh(), 'already_exists'];
                }

                return [
                    VideoDesignImage::create([
                        'project_id' => $projectId,
                        'identity_id' => $spec['identity_id'] ?? null,
                        'image_code' => $this->nextImageCode($projectId, $creator),
                        'image_type' => self::ANCHOR_TYPE,
                        'prompt_spec_json' => $spec,
                        'prompt_sha256' => $sha,
                        'status' => 'candidate',
                        'revision' => 1,
                    ]),
                    'created',
                ];
            });
        } catch (ModelNotFoundException) {
            return [null, 'project_not_found'];
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->findByHash($projectId, $sha);

            if ($existing === null) {
                throw $e;
            }

            return [$existing, 'already_exists'];
        }
    }

    /**
     * O anh neo cua du an, moi nhat truoc, kem ung vien da render.
     *
     * Hien TAT CA o chu khong chi o moi nhat: doi quality hay size la sinh
     * mot o moi, va mot o da tieu tien ma bi giau khoi man hinh la kieu hong te
     * nhat — no van nam trong so cai, chi la khong ai nhin thay.
     *
     * `cost_recorded` doc tu `video_cost_entries`, KHAC `cost_estimate`: uoc
     * luong la thu ta noi truoc khi tra tien, so cai la thu da xay ra.
     *
     * @return list<array<string, mixed>>
     */
    public function anchorCellsFor(string $projectId): array
    {
        $images = VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', self::ANCHOR_TYPE)
            ->with(['artifacts' => fn ($query) => $query->orderBy('created_at')->orderBy('id')])
            ->latest('created_at')
            ->get();

        $cost = $this->costSummaryByImage($projectId, $images->pluck('id')->map('strval')->all());

        return $images
            ->map(fn (VideoDesignImage $image) => $this->cellView($image, $cost[(string) $image->id]))
            ->all();
    }

    /**
     * Ba trang thai khac nhau, khong duoc gop:
     *   khong co dong nao       → he thong chua co dong so cai nao cho o nay
     *   co dong, khong unpriced → so tien la day du
     *   co dong unpriced        → da tieu ma khong biet bao nhieu, KE CA khi mot
     *                             dong khac trong cung o da co gia
     *
     * `has_ledger` doc tu chinh phep SUM chu khong suy tu `recorded > 0`: mot lan
     * lat anh ton dung 0 dong van la mot lan da xay ra.
     *
     * `estimated` doc tu so cai chu khong tra lai bang gia: no la con so DA DONG BANG
     * luc render, nen doi bang gia hom nay khong viet lai lich su.
     *
     * @param  list<string>  $imageIds
     * @return array<string, array{recorded: float, has_ledger: bool, has_unpriced: bool, estimated: float, has_estimate: bool}>
     */
    public function costSummaryByImage(string $projectId, array $imageIds): array
    {
        $summary = array_fill_keys(
            array_map('strval', $imageIds),
            [
                'recorded' => 0.0,
                'has_ledger' => false,
                'has_unpriced' => false,
                'estimated' => 0.0,
                'has_estimate' => false,
                'unclassified' => 0.0,
                'has_unclassified' => false,
            ],
        );

        if ($summary === []) {
            return [];
        }

        $entries = VideoCostEntry::query()
            ->where('project_id', $projectId)
            ->where('entity_type', 'design_image')
            ->whereIn('entity_id', array_keys($summary));

        // `recorded` chi cong tien DA XAC NHAN. Hang cu mang uoc tinh trong cot tien
        // (pricing=estimated, chua co metadata estimate) thi doc bang COALESCE va
        // xep sang cot uoc tinh — khong migration, chi doi cach doc.
        $totals = (clone $entries)
            ->selectRaw(
                'entity_id,'
                ."SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.pricing')) "
                ."IN ('reported', 'reconciled', 'free') THEN cost_usd ELSE 0 END) as confirmed,"
                ."SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.pricing')) = 'estimated' "
                ."THEN COALESCE(JSON_EXTRACT(metadata_json, '$.estimated_cost_usd'), cost_usd) ELSE 0 END) as estimated,"
                ."SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.pricing')) = 'estimated' "
                ."THEN 1 ELSE 0 END) as estimates,"
                // Hang cu khong mang khoa `pricing` nao: khong ai biet con so do la
                // uoc tinh hay tien that, nen no khong duoc dung o ca hai cot tren.
                ."SUM(CASE WHEN JSON_EXTRACT(metadata_json, '$.pricing') IS NULL THEN cost_usd ELSE 0 END) as unclassified,"
                ."SUM(CASE WHEN JSON_EXTRACT(metadata_json, '$.pricing') IS NULL THEN 1 ELSE 0 END) as unclassifieds",
            )
            ->groupBy('entity_id')
            ->get();

        foreach ($totals as $row) {
            $id = (string) $row->entity_id;

            $summary[$id]['recorded'] = (float) $row->confirmed;
            $summary[$id]['has_ledger'] = true;
            $summary[$id]['estimated'] = (float) $row->estimated;
            $summary[$id]['has_estimate'] = (int) $row->estimates > 0;
            $summary[$id]['unclassified'] = (float) $row->unclassified;
            $summary[$id]['has_unclassified'] = (int) $row->unclassifieds > 0;
        }

        $unpriced = (clone $entries)
            ->where('metadata_json->pricing', 'unpriced')
            ->distinct()
            ->pluck('entity_id');

        foreach ($unpriced as $id) {
            $summary[(string) $id]['has_unpriced'] = true;
        }

        return $summary;
    }

    /** @return list<array<string, mixed>> */
    public function referenceCellsFor(string $projectId): array
    {
        $images = VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', self::REFERENCE_TYPE)
            ->with(['artifacts' => fn ($query) => $query->orderBy('created_at')->orderBy('id')])
            ->orderBy('slot_index')
            ->get();

        $cost = $this->costSummaryByImage($projectId, $images->pluck('id')->map('strval')->all());

        return $images
            ->map(fn (VideoDesignImage $image) => $this->cellView($image, $cost[(string) $image->id]))
            ->all();
    }

    public function approvedAnchorFor(string $projectId, string $subjectKey): ?VideoDesignImage
    {
        return $this->whereAnchorSubject(VideoDesignImage::query(), $subjectKey)
            ->where('project_id', $projectId)
            ->where('image_type', self::ANCHOR_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->with('artifact')
            ->orderByDesc('approved_at')
            ->first();
    }

    public static function anchorSubjectKey(VideoDesignImage $image): string
    {
        $spec = is_array($image->prompt_spec_json) ? $image->prompt_spec_json : [];
        $subjectKey = $spec['subject_key'] ?? null;

        return is_string($subjectKey) && $subjectKey !== ''
            ? $subjectKey
            : VisualIdentityStore::DEFAULT_SUBJECT_KEY;
    }

    private function whereAnchorSubject(Builder $query, string $subjectKey): Builder
    {
        return $query->whereRaw(
            "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(prompt_spec_json, '$.subject_key')), ?) = ?",
            [VisualIdentityStore::DEFAULT_SUBJECT_KEY, $subjectKey],
        );
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{0: ?VideoDesignImage, 1: string} [$image, $reason]
     *                                                reason: created|already_exists|project_not_found
     */
    public function createReference(string $projectId, string $creator, array $spec): array
    {
        $spec = $this->withPriceSnapshot($spec);
        $sha = $this->identityHash($spec, array_merge([
            'source_artifact_sha256',
            'view_key',
            'environment',
            'identity_lock_hash',
            'derivation_version',
        ], ($spec['derivation_version'] ?? null) === ReferencePromptWriter::DERIVATION_VERSION
            ? ['identity_anchor_artifact_id', 'identity_anchor_sha256']
            : []));

        try {
            return DB::transaction(function () use ($projectId, $creator, $spec, $sha) {
                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

                $existing = $this->findReferenceByHash($projectId, $sha);

                if ($existing !== null) {
                    return [$existing, 'already_exists'];
                }

                return [
                    VideoDesignImage::create([
                        'project_id' => $projectId,
                        'identity_id' => $spec['identity_id'] ?? null,
                        'image_code' => $this->nextImageCode($projectId, $creator, '_reference_v'),
                        'image_type' => self::REFERENCE_TYPE,
                        'slot_index' => $spec['slot_index'],
                        'source_image_id' => $spec['source_image_id'],
                        'prompt_spec_json' => $spec,
                        'prompt_sha256' => $sha,
                        'status' => 'candidate',
                        'revision' => 1,
                    ]),
                    'created',
                ];
            });
        } catch (ModelNotFoundException) {
            return [null, 'project_not_found'];
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->findReferenceByHash($projectId, $sha);

            if ($existing === null) {
                throw $e;
            }

            return [$existing, 'already_exists'];
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{0: ?VideoDesignImage, 1: string} [$image, $reason]
     *                                                reason: created|already_exists|project_not_found
     */
    public function createEnvironment(string $projectId, string $creator, array $spec): array
    {
        $spec = $this->withPriceSnapshot($spec);
        $extra = ['operation', 'spec_version', 'environment_key'];

        if (($spec['provider'] ?? 'openai') !== 'openai') {
            $extra[] = 'provider';
        }

        $sha = $this->identityHash($spec, $extra);

        try {
            return DB::transaction(function () use ($projectId, $creator, $spec, $sha) {
                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

                $existing = $this->findEnvironmentByHash($projectId, $sha);

                if ($existing !== null) {
                    return [$existing, 'already_exists'];
                }

                return [
                    VideoDesignImage::create([
                        'project_id' => $projectId,
                        'image_code' => $this->nextImageCode($projectId, $creator, '_environment_v'),
                        'image_type' => self::ENVIRONMENT_TYPE,
                        'environment_key' => $spec['environment_key'],
                        'prompt_spec_json' => $spec,
                        'prompt_sha256' => $sha,
                        'status' => DesignImageStatus::CANDIDATE->value,
                        'revision' => 1,
                    ]),
                    'created',
                ];
            });
        } catch (ModelNotFoundException) {
            return [null, 'project_not_found'];
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->findEnvironmentByHash($projectId, $sha);

            if ($existing === null) {
                throw $e;
            }

            return [$existing, 'already_exists'];
        }
    }

    private function findEnvironmentByHash(string $projectId, string $sha): ?VideoDesignImage
    {
        return VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', self::ENVIRONMENT_TYPE)
            ->where('prompt_sha256', $sha)
            ->first();
    }

    /** @return list<array<string, mixed>> */
    public function environmentCellsFor(string $projectId): array
    {
        $images = VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', self::ENVIRONMENT_TYPE)
            ->with(['artifacts' => fn ($query) => $query->orderBy('created_at')->orderBy('id')])
            ->orderBy('created_at')
            ->get();

        $cost = $this->costSummaryByImage($projectId, $images->pluck('id')->map('strval')->all());

        return $images
            ->map(fn (VideoDesignImage $image) => $this->cellView($image, $cost[(string) $image->id])
                + ['environment_key' => (string) $image->environment_key])
            ->all();
    }

    /** @param array<string, mixed> $spec */
    public function createSceneCandidate(
        VideoRenderScene $scene,
        array $spec,
        string $sha,
    ): VideoDesignImage {
        $spec = $this->withPriceSnapshot($spec);

        return VideoDesignImage::create([
            'project_id' => $scene->project_id,
            'render_scene_id' => $scene->id,
            'image_code' => $this->nextImageCode(
                (string) $scene->project_id, (string) $scene->scene_code, '_keyframe_v',
            ),
            'image_type' => self::SCENE_KEYFRAME_TYPE,
            'source_image_id' => $spec['sources'][0]['candidate_id'],
            'prompt_spec_json' => $spec,
            'prompt_sha256' => $sha,
            'status' => DesignImageStatus::CANDIDATE->value,
            'revision' => 1,
        ]);
    }

    private function findReferenceByHash(string $projectId, string $sha): ?VideoDesignImage
    {
        return VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', self::REFERENCE_TYPE)
            ->where('prompt_sha256', $sha)
            ->first();
    }

    /**
     * Duyet mot ung vien lam anchor. KHONG tieu tien va dao nguoc duoc: duyet
     * anh khac thi o cu tu ha xuong `superseded`.
     *
     * Khoa hang DU AN truoc, giong `DesignImageQueue::claimForDirectRender()`:
     * khoa rieng hang dich khong tuan tu hoa duoc phep quet sibling, hai nguoi
     * duyet cung luc se de ra hai o `approved`.
     *
     * @param  ?string  $expectedImageId  Neu co, artifact PHAI thuoc dung o nay.
     * @param  ?string  $expectedImageType  Neu co, o PHAI dung loai nay.
     * @param  ?string  $expectedRenderSceneId  Neu co, o PHAI thuoc dung canh nay.
     * @return array{0: bool, 1: string}
     *          reason: approved|project_not_found|artifact_not_found|image_not_found|
     *                  artifact_not_in_candidate|image_type_mismatch|candidate_outside_scene|
     *                  not_approvable
     */
    public function approve(
        string $projectId,
        string $artifactId,
        ?string $adminId,
        ?string $expectedImageId = null,
        ?string $expectedImageType = null,
        ?string $expectedRenderSceneId = null,
    ): array {
        try {
            return DB::transaction(function () use (
                $projectId, $artifactId, $adminId,
                $expectedImageId, $expectedImageType, $expectedRenderSceneId,
            ): array {
                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

                $artifact = VideoArtifact::query()->whereKey($artifactId)->first();

                if ($artifact === null || $artifact->design_image_id === null) {
                    return [false, 'artifact_not_found'];
                }

                if ($expectedImageId !== null
                    && (string) $artifact->design_image_id !== $expectedImageId) {
                    return [false, 'artifact_not_in_candidate'];
                }

                $image = VideoDesignImage::query()
                    ->where('project_id', $projectId)
                    ->whereKey($artifact->design_image_id)
                    ->first();

                if ($image === null) {
                    return [false, 'image_not_found'];
                }

                if ($expectedImageType !== null && $image->image_type !== $expectedImageType) {
                    return [false, 'image_type_mismatch'];
                }

                if ($expectedRenderSceneId !== null
                    && (string) $image->render_scene_id !== $expectedRenderSceneId) {
                    return [false, 'candidate_outside_scene'];
                }

                if (! in_array($image->status, [
                    DesignImageStatus::RENDERED->value,
                    DesignImageStatus::APPROVED->value,
                ], true)) {
                    return [false, 'not_approvable'];
                }

                $previousArtifactId = $image->selected_artifact_id;

                $this->sameSlot($projectId, $image)
                    ->whereKeyNot($image->id)
                    ->where('status', DesignImageStatus::APPROVED->value)
                    ->update(['status' => DesignImageStatus::SUPERSEDED->value]);

                $image->forceFill([
                    'selected_artifact_id' => $artifact->id,
                    'status' => DesignImageStatus::APPROVED->value,
                    'approved_at' => now(),
                    'approved_by' => $adminId,
                ])->save();

                if ($image->image_type === self::SCENE_KEYFRAME_TYPE
                    && $image->render_scene_id !== null
                    && (string) $previousArtifactId !== (string) $artifact->id) {
                    app(\App\Video\Scene\Services\ShotIntentService::class)
                        ->invalidateForKeyframe((string) $image->render_scene_id);
                }

                return [true, 'approved'];
            });
        } catch (ModelNotFoundException $e) {
            return [false, 'project_not_found'];
        }
    }

    /**
     * @return array{0: bool, 1: string}
     *          reason: deleted|project_not_found|artifact_not_found|image_not_found|
     *                  image_type_mismatch|artifact_in_use|artifact_used_by|<places>|render_in_flight|file_not_deleted|
     *                  delete_failed|file_left_in_trash|<disk:trash path>
     */
    public function deleteCandidate(string $projectId, string $artifactId, string $expectedImageType): array
    {
        $trashed = null;

        try {
            $result = DB::transaction(function () use ($projectId, $artifactId, $expectedImageType, &$trashed): array {
                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

                $artifact = VideoArtifact::query()->whereKey($artifactId)->lockForUpdate()->first();

                if ($artifact === null || $artifact->design_image_id === null) {
                    return [false, 'artifact_not_found'];
                }

                $image = VideoDesignImage::query()
                    ->where('project_id', $projectId)
                    ->whereKey($artifact->design_image_id)
                    ->lockForUpdate()
                    ->first();

                if ($image === null) {
                    return [false, 'image_not_found'];
                }

                if ($image->image_type !== $expectedImageType) {
                    return [false, 'image_type_mismatch'];
                }

                if ((string) $image->selected_artifact_id === (string) $artifact->id) {
                    return [false, 'artifact_in_use'];
                }

                if (! in_array($image->status, [
                    DesignImageStatus::RENDERED->value,
                    DesignImageStatus::APPROVED->value,
                    DesignImageStatus::SUPERSEDED->value,
                    DesignImageStatus::FAILED->value,
                ], true)) {
                    return [false, 'render_in_flight'];
                }

                $users = $this->artifactUsers($projectId, (string) $artifact->id, (string) $image->id);

                if ($users !== []) {
                    return [false, 'artifact_used_by|'.implode(', ', $users)];
                }

                $disk = (string) $artifact->storage_disk;
                $path = (string) $artifact->storage_path;
                $shared = $path === '' || VideoArtifact::query()
                    ->whereKeyNot($artifact->id)
                    ->where('storage_disk', $disk)
                    ->where('storage_path', $path)
                    ->exists();

                if (! $shared && ! $this->trashFile($disk, $path, (string) $artifact->id, $trashed)) {
                    return [false, 'file_not_deleted'];
                }

                $artifact->delete();

                if (! VideoArtifact::query()->where('design_image_id', $image->id)->exists()) {
                    $image->delete();
                }

                return [true, 'deleted'];
            });
        } catch (ModelNotFoundException $e) {
            $result = [false, 'project_not_found'];
        } catch (\Throwable $e) {
            Log::warning('design-image: candidate delete rolled back', ['artifact_id' => $artifactId, 'error' => $e->getMessage()]);

            $result = [false, 'delete_failed'];
        }

        if ($trashed === null) {
            return $result;
        }

        if ($result[0]) {
            $this->purgeTrash($trashed, $artifactId);

            return $result;
        }

        return $this->restoreFile($trashed, $artifactId)
            ? $result
            : [false, 'file_left_in_trash|'.$trashed['disk'].':'.$trashed['trash']];
    }

    /**
     * @param  ?array{disk: string, path: string, trash: string}  $trashed  set as soon as the file has left its path
     */
    private function trashFile(string $disk, string $path, string $artifactId, ?array &$trashed): bool
    {
        $trash = 'trash/design-images/'.$artifactId.'/'.basename($path);
        $storage = null;

        try {
            $storage = Storage::disk($disk);

            if (! $storage->exists($path)) {
                return true;
            }

            $moved = $storage->move($path, $trash);

            if ($moved) {
                $trashed = ['disk' => $disk, 'path' => $path, 'trash' => $trash];
            }

            if ($moved && ! $storage->exists($path)) {
                return true;
            }

            $error = $moved ? 'the file is still at its path after the move' : 'move returned false';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if ($trashed === null) {
            try {
                if ($storage !== null && $storage->exists($trash)) {
                    $trashed = ['disk' => $disk, 'path' => $path, 'trash' => $trash];
                }
            } catch (\Throwable $probe) {
                Log::critical('design-image: cannot tell whether the candidate file reached trash', [
                    'artifact_id' => $artifactId, 'disk' => $disk, 'path' => $path, 'trash' => $trash, 'error' => $probe->getMessage(),
                ]);
            }
        }

        Log::warning('design-image: candidate file could not be moved aside; the record is kept', [
            'artifact_id' => $artifactId, 'disk' => $disk, 'path' => $path, 'error' => $error,
        ]);

        return false;
    }

    /** @param  array{disk: string, path: string, trash: string}  $trashed */
    private function restoreFile(array $trashed, string $artifactId): bool
    {
        try {
            if (Storage::disk($trashed['disk'])->move($trashed['trash'], $trashed['path'])) {
                return true;
            }

            $error = 'move returned false';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        Log::critical('design-image: delete rolled back but the file stayed in trash — move it back by hand', [
            'artifact_id' => $artifactId, 'disk' => $trashed['disk'], 'trash' => $trashed['trash'], 'path' => $trashed['path'], 'error' => $error,
        ]);

        return false;
    }

    /** @param  array{disk: string, path: string, trash: string}  $trashed */
    private function purgeTrash(array $trashed, string $artifactId): void
    {
        try {
            $storage = Storage::disk($trashed['disk']);

            if ($storage->delete($trashed['trash'])) {
                $storage->deleteDirectory(dirname($trashed['trash']));

                return;
            }

            $error = 'delete returned false';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        Log::warning('design-image: deleted candidate left a file in trash', [
            'artifact_id' => $artifactId, 'disk' => $trashed['disk'], 'trash' => $trashed['trash'], 'error' => $error,
        ]);
    }

    /** @return list<string> */
    private function artifactUsers(string $projectId, string $artifactId, string $ownImageId): array
    {
        $like = '%'.$artifactId.'%';
        $users = [];

        if (VideoProject::query()->whereKey($projectId)->where('metadata_json', 'like', $like)->exists()) {
            $users[] = 'bộ chọn của dự án (Reference đã chốt / ảnh neo)';
        }

        if (VideoDesignImage::query()->where('project_id', $projectId)->whereKeyNot($ownImageId)
            ->where(fn (Builder $query) => $query->where('prompt_spec_json', 'like', $like)->orWhere('metadata_json', 'like', $like))
            ->exists()) {
            $users[] = 'nguồn của ảnh khác (keyframe / reference / tấm nền)';
        }

        if (VideoRenderScene::query()->where('project_id', $projectId)->where('state_json', 'like', $like)->exists()) {
            $users[] = 'ảnh tham chiếu đã chọn của shot';
        }

        if (DB::table('video_renders')
            ->where(fn ($query) => $query->whereNull('design_image_id')->orWhere('design_image_id', '!=', $ownImageId))
            ->where(fn ($query) => $query->where('request_json', 'like', $like)
                ->orWhere('render_request_json', 'like', $like)
                ->orWhere('artifact_manifest', 'like', $like))
            ->exists()) {
            $users[] = 'lượt render đã ghi';
        }

        foreach (['video_reference_assets', 'approved_anchors', 'reference_pack_assets', 'render_qa_runs'] as $table) {
            if (DB::table($table)->where('artifact_id', $artifactId)->exists()) {
                $users[] = $table;
            }
        }

        return $users;
    }

    /**
     * Cung mot CHO trong bang thiet ke, khong phai cung mot du an: duyet o
     * `keel` khong duoc ha o `hall` xuong superseded.
     *
     * `whereNull` la bat buoc — ba cot nay dang NULL tren moi o anchor hien co,
     * ma `WHERE state = NULL` khong bao gio khop trong SQL.
     */
    private function sameSlot(string $projectId, VideoDesignImage $image): Builder
    {
        $query = VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', $image->image_type);

        foreach (['state', 'slot_index', 'proves_state', 'render_scene_id', 'environment_key'] as $column) {
            $image->{$column} === null
                ? $query->whereNull($column)
                : $query->where($column, $image->{$column});
        }

        if ($image->image_type === self::ANCHOR_TYPE) {
            $this->whereAnchorSubject($query, self::anchorSubjectKey($image));
        }

        return $query;
    }

    /**
     * @param  array{recorded: float, has_ledger: bool, has_unpriced: bool}  $cost
     * @return array<string, mixed>
     */
    private function cellView(VideoDesignImage $image, array $cost): array
    {
        $spec = $image->prompt_spec_json ?? [];
        $variations = max(1, (int) ($spec['variations'] ?? 1));
        $pricing = (string) ($spec['pricing'] ?? 'estimated');
        $frozen = $spec['unit_cost_usd'] ?? null;
        // Doc gia DA DONG BANG trong spec; chi hang OpenAI cu (chua co khoa nay) moi
        // lui ve bang gia theo quality. Gemini khong bao gio dung bang cua OpenAI.
        $unit = match (true) {
            $pricing === 'unpriced' => null,
            is_int($frozen) || is_float($frozen) => (float) $frozen > 0 ? (float) $frozen : null,
            (string) ($spec['provider'] ?? 'openai') === 'openai' => ImageQuality::fromSpecOrHigh($spec['quality'] ?? '')->estimatedCostUsd(),
            default => null,
        };

        return [
            'id' => $image->id,
            'image_code' => $image->image_code,
            'status' => $image->status,
            'status_label' => DesignImageStatus::tryFrom($image->status)?->label() ?? $image->status,
            'is_live' => in_array($image->status, array_merge(
                [DesignImageStatus::QUEUED->value], DesignImageStatus::leasedValues(),
            ), true),
            'can_render' => in_array($image->status, DesignImageStatus::enqueueableValues(), true),
            'has_failed' => $image->status === DesignImageStatus::FAILED->value,
            'status_tone' => match ($image->status) {
                DesignImageStatus::APPROVED->value => 'ok',
                DesignImageStatus::RENDERED->value => 'blue',
                DesignImageStatus::FAILED->value => 'dg',
                DesignImageStatus::SUPERSEDED->value => 'mute',
                default => 'amber',
            },
            'selected_artifact_id' => $image->selected_artifact_id,
            'can_approve' => in_array($image->status, [
                DesignImageStatus::RENDERED->value,
                DesignImageStatus::APPROVED->value,
            ], true),
            'render_error' => $image->render_error,
            'queued_at' => $image->queued_at,
            'worker' => $image->worker_id,
            'view_key' => $spec['view_key'] ?? null,
            'character_id' => $spec['character_id'] ?? null,
            'character_name' => $spec['character_name'] ?? null,
            'design_stage_id' => $spec['design_stage_id'] ?? null,
            'design_content_hash' => $spec['design_content_hash'] ?? null,
            'environment' => $spec['environment'] ?? null,
            'quality' => (string) ($spec['quality'] ?? ''),
            'size' => (string) ($spec['size'] ?? ''),
            'variations' => $variations,
            'pricing' => $pricing,
            'provider' => (string) ($spec['provider'] ?? 'openai'),
            'cost_unit' => $unit,
            'cost_estimate' => $unit === null ? null : $unit * $variations,
            'cost_recorded' => $cost['recorded'],
            'cost_recorded_has_ledger' => $cost['has_ledger'],
            'cost_recorded_unpriced' => $cost['has_unpriced'],
            'cost_recorded_estimated' => $cost['estimated'],
            'cost_recorded_has_estimate' => $cost['has_estimate'],
            'cost_recorded_unclassified' => $cost['unclassified'],
            'cost_recorded_has_unclassified' => $cost['has_unclassified'],
            'candidates' => $image->artifacts->map(fn (VideoArtifact $artifact) => [
                'id' => $artifact->id,
                'url' => $artifact->storage_disk === 'public'
                    ? $artifact->storage_path
                    : route('video-artifacts.show', $artifact->id),
                'width' => $artifact->width,
                'height' => $artifact->height,
                'created_at' => $artifact->created_at,
                'sha' => substr((string) $artifact->sha256, 0, 12),
            ])->all(),
        ];
    }

    public function nextImageCode(string $projectId, string $creator, string $suffix = self::CODE_SUFFIX): string
    {
        $slug = Str::slug($creator, '_');

        if ($slug === '') {
            throw new \InvalidArgumentException('nextImageCode: creator name is required');
        }

        $now = now();
        $stamp = $now->format('dmY').'_'.$now->format('His');
        $room = self::CODE_MAX - strlen(self::CODE_PREFIX.'_'.$stamp.$suffix) - 3;
        $prefix = self::CODE_PREFIX.Str::limit($slug, max(1, $room), '').'_'.$stamp.$suffix;

        return $prefix.($this->highestNumberToday($projectId, $now, $suffix) + 1);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<string>  $extraKeys
     */
    public function identityHash(array $spec, array $extraKeys = []): string
    {
        $keys = array_merge(self::IDENTITY_KEYS, $extraKeys);
        $missing = array_diff($keys, array_keys($spec));

        if ($missing !== []) {
            throw new \InvalidArgumentException('identityHash: missing '.implode(', ', $missing));
        }

        $identity = array_intersect_key($spec, array_flip($keys));
        $this->sortDeep($identity);

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function findByHash(string $projectId, string $sha): ?VideoDesignImage
    {
        return VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('prompt_sha256', $sha)
            ->first();
    }

    /** @param array<string, mixed> $value */
    private function sortDeep(array &$value): void
    {
        ksort($value);

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortDeep($item);
            }
        }
    }

    private function highestNumberToday(string $projectId, \DateTimeInterface $now, string $suffix): int
    {
        $max = 0;
        $dayMark = '%_'.$now->format('dmY').'_%'.$suffix.'%';

        foreach (VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_code', 'like', $dayMark)
            ->pluck('image_code') as $code) {
            if (preg_match('/'.preg_quote($suffix, '/').'(\d+)$/', $code, $found)) {
                $max = max($max, (int) $found[1]);
            }
        }

        return $max;
    }
}
