<?php

namespace App\Video\Render\Video;

use App\Models\VideoArtifact;
use App\Models\VideoProviderSubmissionReceipt;
use App\Models\VideoRender;
use App\Models\VideoShot;
use App\Video\Media\Mp4Probe;
use App\Video\Media\VideoModelRegistry;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\RenderDispatchService;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use RuntimeException;

/**
 * Clip sinh ra TU khung hinh da duyet cua chinh scene do, nen no thua ca bo hash
 * cua lan render khung hinh ay. Khong dung lai bo hash nay thi hai lan render
 * cung mot scene co the khai hai canonical khac nhau.
 *
 * Anh nguon duoc dong bang ca `disk` chu khong chi `path`: `artifact_path` cua
 * duong cu co the tro ra ngoai moi disk cua Laravel, va luc do clip khong tai lap
 * duoc — phai bao ngay o day chu khong phai sau khi da tra tien.
 */
final class SceneClipDispatchService
{
    public const TASK = 'scene_clip';

    // v4 doi `durationSeconds` tu chuoi sang JSON number. Phai doi version de
    // request_hash khong the tro lai hang da tao theo wire contract cu.
    public const PROVIDER_PAYLOAD_VERSION = 'veo-image-to-video-v4';

    public function __construct(
        private readonly RenderDispatchService $dispatch,
        private readonly VideoModelRegistry $registry,
        private readonly FilesystemFactory $storage,
        private readonly Mp4Probe $probe,
    ) {}

    /**
     * @param  VideoArtifact  $source  anh DA DUYET cua chinh scene nay
     * @param  array<string, mixed>  $controls
     */
    public function create(VideoShot $shot, VideoArtifact $source, string $modelId, array $controls = []): VideoRender
    {
        // Thieu ffprobe la loi cau hinh may, khong phai loi cua render: chan o day
        // thi khong o nao bi tao ra roi danh that bai vinh vien.
        $missing = $this->probe->problem();

        if ($missing !== null) {
            throw new RuntimeException(
                'Khong do duoc video vi '.$missing
                .' Chua sua duoc thi khong tao clip, de khoi tra tien cho mot file khong xac minh duoc.',
            );
        }

        $entry = $this->registry->find(self::TASK, $modelId);

        if ($entry === null) {
            throw new RuntimeException('Model clip ngoai registry: '.$modelId);
        }

        // Anh nguon la ARTIFACT DA DUYET, khong phai trang thai cua hang render:
        // duyet la hanh dong cua nguoi, va file kem sha256 moi la bang chung.
        //
        // Hang render cua no van duoc doc — nhung chi de thua lai bo hash canonical,
        // de hai lan render cung mot scene khong khai hai canonical khac nhau.
        $keyframe = $source->render_id === null
            ? null
            : VideoRender::query()->whereKey($source->render_id)->first();

        if ($keyframe === null) {
            throw new RuntimeException('Anh da duyet khong gan voi lan render nao — khong truy duoc canonical.');
        }

        $prompt = trim((string) $shot->compiled_prompt);

        if ($prompt === '') {
            throw new RuntimeException('Shot chua co compiled_prompt cho chuyen dong.');
        }

        $request = [
            'kind' => self::TASK,
            // Provider tu choi `inlineData` trong canary dau tien. Wire shape la
            // mot phan cua request da dong bang, de retry khong am tham doi bytes.
            'provider_payload_version' => self::PROVIDER_PAYLOAD_VERSION,
            'provider' => $entry['provider'],
            'model' => $entry['model'],
            'api_version' => $entry['api_version'],
            'compiled_prompt' => ['prompt' => $prompt],
            'duration_seconds' => $this->choice($entry, $controls, 'durations', 'default_duration', 'duration_seconds'),
            'aspect_ratio' => $this->choice($entry, $controls, 'aspect_ratios', 'default_aspect_ratio', 'aspect_ratio'),
            'resolution' => $this->choice($entry, $controls, 'resolutions', 'default_resolution', 'resolution'),
            // Hop dong: image-to-video CHI nhan `allow_adult`
            // (veo_video_contract_2026_09_15.json). No la hang so cua hop dong, nhung
            // van phai nam trong spec — thu gi da gui di thi phai doc lai duoc tu day,
            // va request_hash phai phu duoc len no.
            'person_generation' => 'allow_adult',
            'source_render_id' => $keyframe->id,
            'source_artifact_id' => (string) $source->id,
            'source_artifact' => $this->freezeSource($source),
            'motion_spec_hash' => $this->motionSpecHash($shot),
        ];

        $this->assertCombination($entry, $request);

        $requestJson = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $requestHash = hash('sha256', $requestJson);

        // CHECK `video_renders_one_owner` cho dung MOT chu so huu. Chu cua clip la
        // shot; session suy ra duoc qua shot, con dat ca hai la vi pham rang buoc.
        return $this->dispatch->create(
            sessionId: null,
            assetId: (string) ($shot->scene_id ?? $shot->shot_code),
            provider: (string) $entry['provider'],
            model: (string) $entry['model'],
            idempotencyKey: $this->idempotencyKey($shot, $requestHash),
            requestJson: $requestJson,
            requestHash: $requestHash,
            canonicalRevisionId: (string) $keyframe->canonical_concept_revision_id,
            canonicalHash: (string) $keyframe->canonical_hash,
            projectionHash: (string) $keyframe->projection_hash,
            constraintSetHash: (string) $keyframe->constraint_set_hash,
            promptSpecHash: (string) $keyframe->prompt_spec_hash,
            providerPromptPlanHash: (string) $keyframe->provider_prompt_plan_hash,
            promptHash: hash('sha256', $prompt),
            renderKind: 'video',
            shotId: $shot->id,
        );
    }

    /**
     * Lam lai mot luot DA HONG phai la mot HANG MOI, khong phai hoi sinh hang cu:
     * `VideoRender` la su kien da xay ra, bat bien.
     *
     * Nhung chi duoc lam lai khi KHONG con bien lai nao: co bien lai nghia la
     * provider da nhan job va tien da di. Luc do gui lai la tra tien lan hai cho
     * cung mot canh — phai de nguoi doi soat quyet, khong phai mot cu bam.
     */
    private function idempotencyKey(VideoShot $shot, string $requestHash): string
    {
        $base = 'scene-clip:'.$shot->id.':'.$requestHash;

        $existing = VideoRender::query()
            ->where('shot_id', $shot->id)
            ->where('idempotency_key', 'like', $base.'%')
            ->get(['id', 'execution_status', 'idempotency_key']);

        if ($existing->isEmpty()) {
            return $base;
        }

        // Luot chua ket thuc thi dung lai chinh no — day moi la idempotency that.
        $live = $existing->first(static fn (VideoRender $render): bool => ! in_array(
            $render->execution_status,
            [RenderStatus::FAILED, RenderStatus::CANCELLED],
            true,
        ));

        if ($live !== null) {
            return (string) $live->idempotency_key;
        }

        $paid = VideoProviderSubmissionReceipt::query()
            ->whereIn('render_id', $existing->pluck('id'))
            ->exists();

        if ($paid) {
            throw new RuntimeException(
                'Luot truoc da duoc provider nhan job — khong tu dung lai, de khoi tra tien hai lan cho cung mot canh.',
            );
        }

        return $base.':r'.$existing->count();
    }

    /** @return array{disk: string, path: string, sha256: string, mime: string, bytes: int} */
    private function freezeSource(VideoArtifact $source): array
    {
        $disk = (string) $source->storage_disk;
        $path = (string) $source->storage_path;
        $maxBytes = (int) config('video.veo.max_source_bytes', 33554432);

        if ($path === '') {
            throw new RuntimeException('Anh da duyet khong co storage_path.');
        }

        if (! in_array($disk, (array) config('video.veo.source_disks', ['video_artifacts']), true)) {
            throw new RuntimeException('Anh da duyet nam tren disk khong duoc phep: '.$disk);
        }

        $filesystem = $this->storage->disk($disk);

        if (! $filesystem->exists($path)) {
            throw new RuntimeException('Khong thay file anh da duyet tren disk '.$disk.': '.$path);
        }

        $size = (int) $filesystem->size($path);

        if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException('Anh da duyet co kich thuoc khong hop le: '.$size);
        }

        $bytes = (string) $filesystem->get($path);

        if (! hash_equals((string) $source->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('File anh da duyet khac sha256 da ghi trong artifact.');
        }

        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';

        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            throw new RuntimeException('Anh da duyet khong phai PNG/JPEG: '.($mime !== '' ? $mime : 'khong doc duoc'));
        }

        return ['disk' => $disk, 'path' => $path, 'sha256' => hash('sha256', $bytes),
            'mime' => $mime, 'bytes' => $size];
    }

    /**
     * Registry noi tung o duoc chon gi, nhung khong noi hai o co di duoc voi nhau
     * khong. Rang buoc cheo nam o day, va chan TRUOC khi request roi khoi may.
     *
     * @param  array<string, mixed>  $request
     */
    private function assertCombination(array $entry, array $request): void
    {
        $long = (array) ($entry['controls']['long_resolutions'] ?? []);
        $required = $entry['controls']['long_resolution_duration'] ?? null;

        if ($long === [] || $required === null) {
            return;
        }

        $resolution = (string) $request['resolution'];
        $duration = (int) $request['duration_seconds'];

        if (in_array($resolution, $long, true) && $duration !== (int) $required) {
            throw new RuntimeException(sprintf(
                '%s chi nhan thoi luong %ds, dang chon %ds.',
                $resolution,
                (int) $required,
                $duration,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $controls
     * @return mixed
     */
    private function choice(array $entry, array $controls, string $listKey, string $defaultKey, string $input)
    {
        $allowed = $entry['controls'][$listKey];
        $value = $controls[$input] ?? $entry['controls'][$defaultKey];

        if (! in_array($value, $allowed, true)) {
            throw new RuntimeException($input.' ngoai registry: '.var_export($value, true));
        }

        return $value;
    }

    private function motionSpecHash(VideoShot $shot): string
    {
        $spec = $shot->spec_json ?? [];

        return hash('sha256', (string) json_encode([
            'motion' => $spec['motion'] ?? [],
            'camera' => $spec['camera'] ?? [],
            'duration_seconds' => $spec['duration_seconds'] ?? null,
            'from_state_id' => $shot->from_state_id,
            'to_state_id' => $shot->to_state_id,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
