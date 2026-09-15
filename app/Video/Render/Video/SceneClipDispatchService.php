<?php

namespace App\Video\Render\Video;

use App\Enums\DesignImageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoProviderSubmissionReceipt;
use App\Models\VideoRender;
use App\Models\VideoRenderScene;
use App\Models\VideoShot;
use App\Services\Video\DesignImageStore;
use App\Video\Scene\ScenePreservationPrompt;
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

    // v5 them `end_frame` vao request. Phai doi version de request_hash khong the
    // tro lai hang da tao theo wire contract cu — va de executor tu choi thang mot
    // hang v4 con dang cho, thay vi gui di mot payload thieu nua hop dong.
    //
    // Hang v4 tro thanh LICH SU CHI DOC. Retry khong hoi sinh hang cu (xem
    // `idempotencyKey()`), no dung mot hang moi — nen hang moi luon la v5.
    public const PROVIDER_PAYLOAD_VERSION = 'veo-image-to-video-v5';

    public const PURPOSE_PRODUCTION = 'production';

    public const PURPOSE_CANARY = 'canary';

    public function __construct(
        private readonly RenderDispatchService $dispatch,
        private readonly VideoModelRegistry $registry,
        private readonly FilesystemFactory $storage,
        private readonly Mp4Probe $probe,
    ) {}

    /**
     * Duong cua man hinh. Anh cuoi do CHINH dich vu nay tra ra tu ban ke hoach,
     * khong bao gio do nguoi goi truyen vao — controller khong co cach nao chi
     * dinh mot anh cuoi tuy y.
     *
     * @param  VideoArtifact  $source  anh DA DUYET cua chinh scene nay
     * @param  array<string, mixed>  $controls
     */
    public function create(VideoShot $shot, VideoArtifact $source, string $modelId, array $controls = []): VideoRender
    {
        return $this->build($shot, $source, self::PURPOSE_PRODUCTION, $modelId, $controls);
    }

    /**
     * Duong canary: KHONG doc co `last_frame`, va chi den tu Artisan.
     *
     * Anh cuoi van phai do `successorFrame()` tra ra. Mot cap anh tuy y chi chung
     * minh Veo nhan hai anh; no khong chung minh duong scene chay dung.
     *
     * @param  array<string, mixed>  $controls
     */
    public function createCanary(
        VideoShot $shot,
        VideoArtifact $source,
        string $modelId,
        array $controls = [],
        ?string $expectedRequestHash = null,
    ): VideoRender {
        return $this->build($shot, $source, self::PURPOSE_CANARY, $modelId, $controls, $expectedRequestHash);
    }

    /**
     * Dong bang request va tra ve DE XEM, khong tao hang nao.
     *
     * Lenh canary phai hoi nguoi dung truoc khi tieu tien, ma cau hoi do chi co
     * nghia khi nguoi doc thay DUNG cai sap duoc gui. Va neu ho tu choi, hoac neu
     * anh cuoi khong phai cai ho mong doi, thi khong duoc de lai mot hang queued
     * nao — mot hang nhu the van an mot `attempt_no` va van bi worker nhin thay.
     *
     * @param  array<string, mixed>  $controls
     * @return array{request: array<string, mixed>, request_hash: string}
     */
    public function preflight(VideoShot $shot, VideoArtifact $source, string $modelId, array $controls = []): array
    {
        [, , $request] = $this->freezeRequest($shot, $source, self::PURPOSE_CANARY, $modelId, $controls);

        return ['request' => $request, 'request_hash' => $this->hashOf($request)];
    }

    /**
     * @param  array<string, mixed>  $controls
     */
    private function build(
        VideoShot $shot,
        VideoArtifact $source,
        string $purpose,
        string $modelId,
        array $controls,
        ?string $expectedRequestHash = null,
    ): VideoRender {
        [$entry, $keyframe, $request] = $this->freezeRequest($shot, $source, $purpose, $modelId, $controls);

        $requestJson = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $requestHash = hash('sha256', $requestJson);

        // Giua luc nguoi dung doc man hinh xac nhan va luc ho go "y", ban ke hoach
        // co the da doi. Chan TRUOC khi tao hang: cai duoc duyet phai la cai duoc gui.
        if ($expectedRequestHash !== null && ! hash_equals($expectedRequestHash, $requestHash)) {
            throw new RuntimeException(
                'Request da doi ke tu luc ban xem — chua tao hang nao. Chay lai de xem ban moi.',
            );
        }

        // CHECK `video_renders_one_owner` cho dung MOT chu so huu. Chu cua clip la
        // shot; session suy ra duoc qua shot, con dat ca hai la vi pham rang buoc.
        return $this->dispatch->create(
            sessionId: null,
            assetId: (string) ($shot->scene_id ?? $shot->shot_code),
            provider: (string) $entry['provider'],
            model: (string) $entry['model'],
            idempotencyKey: $this->idempotencyKey($shot, $requestHash, $purpose),
            requestJson: $requestJson,
            requestHash: $requestHash,
            canonicalRevisionId: (string) $keyframe->canonical_concept_revision_id,
            canonicalHash: (string) $keyframe->canonical_hash,
            projectionHash: (string) $keyframe->projection_hash,
            constraintSetHash: (string) $keyframe->constraint_set_hash,
            promptSpecHash: (string) $keyframe->prompt_spec_hash,
            providerPromptPlanHash: (string) $keyframe->provider_prompt_plan_hash,
            promptHash: hash('sha256', (string) $request['compiled_prompt']['prompt']),
            renderKind: 'video',
            shotId: $shot->id,
            executionPurpose: $purpose,
        );
    }

    /** @param array<string, mixed> $request */
    private function hashOf(array $request): string
    {
        return hash('sha256', (string) json_encode($request, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $controls
     * @return array{0: array<string, mixed>, 1: VideoRender, 2: array<string, mixed>}
     */
    private function freezeRequest(
        VideoShot $shot,
        VideoArtifact $source,
        string $purpose,
        string $modelId,
        array $controls,
    ): array {
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

        // Canary khong doc co: no ton tai de CHUNG MINH co nay co duoc bat khong.
        $attach = $purpose === self::PURPOSE_CANARY
            || ($entry['controls']['last_frame'] ?? false) === true;

        $end = $attach ? $this->successorFrame($shot) : null;

        if ($purpose === self::PURPOSE_CANARY && $end === null) {
            throw new RuntimeException(
                'Scene nay khong co scene ke noi tiep — canary phai chay tren mot scene co scene ke,'
                .' vi no can chung minh dung duong scene chu khong phai mot cap anh bat ky.',
            );
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
            'source_artifact' => $this->freezeFrame($source, $shot->scene_id),
            // Khoa LUON co mat, ke ca khi khong co anh cuoi: `null` cung di vao
            // request_hash, nen "co end frame" va "khong co" khong bao gio ra cung
            // mot hash cho cung mot scene.
            'end_frame' => $end === null
                ? null
                : $this->freezeFrame($end['artifact'], $end['scene_id']),
            'motion_spec_hash' => $this->motionSpecHash($shot),
        ];

        $this->assertCombination($entry, $request, $purpose);
        $this->assertFramesAgree($request);

        return [$entry, $keyframe, $request];
    }

    /**
     * Lam lai mot luot DA HONG phai la mot HANG MOI, khong phai hoi sinh hang cu:
     * `VideoRender` la su kien da xay ra, bat bien.
     *
     * Nhung chi duoc lam lai khi KHONG con bien lai nao: co bien lai nghia la
     * provider da nhan job va tien da di. Luc do gui lai la tra tien lan hai cho
     * cung mot canh — phai de nguoi doi soat quyet, khong phai mot cu bam.
     */
    private function idempotencyKey(VideoShot $shot, string $requestHash, string $purpose): string
    {
        // Hai duong phai co hai DONG idempotency rieng.
        //
        // Khi `last_frame` bat, request cua production va cua canary giong nhau TUNG
        // BYTE — `purpose` nam o cot chu khong nam trong request. Dung chung mot tien
        // to thi hai duong tra ve chinh hang cua nhau: canary nhan lai hang production,
        // va te hon, mot cu bam Render sau canary se nhan lai hang CANARY — ma man hinh
        // loc canary di, nen clip cua nguoi dung bien mat trong khi tien da tieu.
        $prefix = $purpose === self::PURPOSE_CANARY ? 'scene-clip-canary:' : 'scene-clip:';
        $base = $prefix.$shot->id.':'.$requestHash;

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

    /**
     * Anh da duyet duoc dong bang thanh BANG CHUNG, khong phai mot con tro: doc lai
     * dung file nay tu dia thi phai ra dung nhung byte nay.
     *
     * `scene_id` nam TRONG snapshot chu khong ngoai no, vi voi anh cuoi thi "lay tu
     * scene nao" la du lieu chiu luc — no chung minh resolver da chon dung scene ke.
     *
     * @return array{artifact_id: string, scene_id: ?string, disk: string, path: string,
     *               sha256: string, mime: string, bytes: int, width: int, height: int}
     */
    private function freezeFrame(VideoArtifact $source, ?string $sceneId): array
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
        $width = is_array($info) ? (int) ($info[0] ?? 0) : 0;
        $height = is_array($info) ? (int) ($info[1] ?? 0) : 0;

        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            throw new RuntimeException('Anh da duyet khong phai PNG/JPEG: '.($mime !== '' ? $mime : 'khong doc duoc'));
        }

        if ($width <= 0 || $height <= 0) {
            throw new RuntimeException('Khong doc duoc kich thuoc anh da duyet: '.$path);
        }

        return ['artifact_id' => (string) $source->id, 'scene_id' => $sceneId,
            'disk' => $disk, 'path' => $path, 'sha256' => hash('sha256', $bytes),
            'mime' => $mime, 'bytes' => $size, 'width' => $width, 'height' => $height];
    }

    /**
     * Registry noi tung o duoc chon gi, nhung khong noi hai o co di duoc voi nhau
     * khong. Rang buoc cheo nam o day, va chan TRUOC khi request roi khoi may.
     *
     * @param  array<string, mixed>  $request
     */
    private function assertCombination(array $entry, array $request, string $purpose): void
    {
        $this->assertLongResolutionDuration($entry, $request);
        $this->assertLastFrameAllowed($entry, $request, $purpose);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $request
     */
    private function assertLongResolutionDuration(array $entry, array $request): void
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
     * `last_frame` trong registry mang BA trang thai, khong phai hai:
     *
     *   - khong co khoa  => model nay chua he duoc khai cho lastFrame. Tu choi ca
     *     canary: khong co gi de thu, vi chua ai noi model nay nhan duoc anh cuoi.
     *   - `false`        => da khai nhung CHUA CO BANG CHUNG. Production tu choi;
     *     canary duoc di, vi canary ton tai dung de bien `false` thanh `true`.
     *   - `true`         => canary da di qua, production duoc dung.
     *
     * Hai trang thai thi canary tu ket lieu minh: no phai bat co production len moi
     * chay duoc, ma bat co production len chinh la thu no phai chung minh truoc.
     *
     * Va KHONG co luat `last_frame => 8 giay` o day. Tai lieu Veo chi bat 8 giay cho
     * extension, reference images va 1080p/4k; no khong noi gi ve `lastFrame`. Ghi mot
     * luat chua co bang chung vao registry la bien phong doan thanh hop dong.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $request
     */
    private function assertLastFrameAllowed(array $entry, array $request, string $purpose): void
    {
        if (($request['end_frame'] ?? null) === null) {
            return;
        }

        if (! array_key_exists('last_frame', (array) ($entry['controls'] ?? []))) {
            throw new RuntimeException(
                'Model '.((string) $request['model']).' khong khai `last_frame` trong registry.',
            );
        }

        if ($purpose === self::PURPOSE_CANARY) {
            return;
        }

        if ($entry['controls']['last_frame'] !== true) {
            throw new RuntimeException(
                'Model '.((string) $request['model']).' chua bat `last_frame` trong registry.',
            );
        }
    }

    /**
     * Hai khung lech ti le thi model tu cat hoac keo, ma checksum van dung ca hai nen
     * khong co gi bao. Chan o day, TRUOC khi request roi khoi may.
     *
     * Chi so HAI KHUNG VOI NHAU, khong so voi `aspect_ratio` cua lan render: mot khung
     * nguon lech ti le output van chay duoc tu truoc toi nay, va khong co bang chung
     * nao noi no hong. Them rang buoc do o day la doi hanh vi dang chay, khong phai
     * chan cai rui ro that.
     *
     * So bang phan so nguyen chu khong bang so thuc: 1152x2048, 1080x1920 va 720x1280
     * deu la 9:16 nhung khac pixel, nen ep cung kich thuoc la sai.
     *
     * @param  array<string, mixed>  $request
     */
    private function assertFramesAgree(array $request): void
    {
        $start = $request['source_artifact'] ?? null;
        $end = $request['end_frame'] ?? null;

        if (! is_array($start) || ! is_array($end)) {
            return;
        }

        $sw = (int) $start['width'];
        $sh = (int) $start['height'];
        $ew = (int) $end['width'];
        $eh = (int) $end['height'];

        // Nhan cheo, sai lech cho phep 1%.
        $left = $sw * $eh;
        $right = $sh * $ew;

        if (abs($left - $right) * 100 > max($left, $right)) {
            throw new RuntimeException(sprintf(
                'Anh dau %dx%d va anh cuoi %dx%d khac ti le — model se tu cat hoac keo mot trong hai.',
                $sw,
                $sh,
                $ew,
                $eh,
            ));
        }
    }

    /**
     * Anh cuoi cua clip scene N la keyframe DA DUYET cua scene N+1.
     *
     * Bon dieu kien chu khong phai ba: `continuity_group` cung phai khop, vi hang trong
     * DB co the bi chinh thang va `source_scene_code` mot minh khong du de noi hai scene
     * that su cung mot mach.
     *
     * Ba ket qua, va chung KHAC NHAU:
     *   - khong co scene ke  => null. Clip khong bi rang trang thai ket thuc, day la
     *     scene cuoi cua nhom va do la hop le.
     *   - co scene ke, keyframe da duyet => tra ve anh do.
     *   - co scene ke, keyframe CHUA duyet => NEM. Render tiep se sinh ra mot clip ket
     *     thuc o dau khong ai biet, roi scene ke bat dau tu mot anh khac: nhay hinh. Tra
     *     tien cho mot clip chac chan phai lam lai la sai.
     *
     * @return array{artifact: VideoArtifact, scene_id: string, scene_code: string}|null
     */
    private function successorFrame(VideoShot $shot): ?array
    {
        $sceneId = (string) ($shot->scene_id ?? '');

        if ($sceneId === '') {
            return null;
        }

        $scene = VideoRenderScene::query()->whereKey($sceneId)->first();

        if ($scene === null || (string) ($scene->scene_code ?? '') === '') {
            return null;
        }

        $next = VideoRenderScene::query()
            ->where('project_id', $scene->project_id)
            ->where('revision', $scene->revision)
            ->where('source_scene_code', $scene->scene_code)
            ->where('transition_mode', ScenePreservationPrompt::CONTINUATION)
            ->where(fn ($query) => $scene->continuity_group === null
                ? $query->whereNull('continuity_group')
                : $query->where('continuity_group', $scene->continuity_group))
            ->orderBy('scene_index')
            ->first();

        if ($next === null) {
            return null;
        }

        $keyframe = VideoDesignImage::query()
            ->where('render_scene_id', $next->id)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->first();

        $artifact = $keyframe === null || $keyframe->selected_artifact_id === null
            ? null
            : VideoArtifact::query()
                ->whereKey($keyframe->selected_artifact_id)
                ->where('design_image_id', $keyframe->id)
                ->first();

        if ($artifact === null) {
            $name = (string) ($next->title ?? '');

            throw new RuntimeException(sprintf(
                'successor_keyframe_unapproved: scene ke "%s" chua co anh duyet.'
                .' Clip cua scene nay phai ket thuc dung o do, nen duyet keyframe scene "%s" truoc da.',
                $name !== '' ? $name : (string) $next->scene_code,
                (string) $next->scene_code,
            ));
        }

        return [
            'artifact' => $artifact,
            'scene_id' => (string) $next->id,
            'scene_code' => (string) $next->scene_code,
        ];
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
