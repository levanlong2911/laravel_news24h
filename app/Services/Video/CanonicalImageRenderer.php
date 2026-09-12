<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\ImageQuality;
use App\Models\VideoDesignImage;
use App\Models\VideoRender;
use App\Services\PythonRunner;
use App\Video\Render\Attempts\RenderAttemptService;
use App\Video\Render\Claims\RenderClaimService;
use App\Video\Render\DTO\RenderAttemptUsage;
use App\Video\Render\DTO\RenderClaim;
use App\Video\Render\Enums\RenderFailureClass;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\RenderCheckpointService;
use App\Video\Render\RenderDispatchService;
use App\Video\Render\RenderFailureService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Render dong bo qua duong Phan 13/14. Khac `DesignImageDirectRenderer` o ba
 * diem: chay bang RenderRequest co day du lineage, ghi MOT hang `video_renders`
 * di qua may trang thai that, va artifact nam trong disk cua Laravel.
 *
 * Khong dung worker/HTTP callback: may nay phuc vu bang `php artisan serve` —
 * mot tien trinh duy nhat — nen mot cu goi nguoc HTTP trong luc Laravel dang
 * cho se tu khoa chinh no. Cac dich vu Phan 14 duoc goi thang bang PHP.
 */
class CanonicalImageRenderer
{
    private const PREPARE_SCRIPT = 'prepare_render_request.py';

    private const RENDER_SCRIPT = 'render_canonical_image.py';

    private const WORKER_ID = 'laravel:canonical';

    private const PREPARE_SECONDS = 60;

    private const RENDER_SECONDS = 180;

    public function __construct(
        private DesignImageQueue $queue,
        private PythonRunner $pythonRunner,
        private RenderDispatchService $dispatch,
        private RenderClaimService $claims,
        private RenderAttemptService $attempts,
        private RenderCheckpointService $checkpoints,
        private RenderFailureService $failures,
    ) {}

    /**
     * @return array{0: ?VideoDesignImage, 1: string} [$image, $reason]
     */
    public function renderNow(string $imageId): array
    {
        // Doi chieu TRUOC khi claim: bam Generate lai phai la HOAN TAT mot luot
        // da tra tien con nam tren dia, khong phai tra tien them lan nua.
        $this->reconcile((string) VideoDesignImage::query()->whereKey($imageId)->value('project_id'));

        // `Process` co timeout rieng, nhung no KHONG noi gioi han thuc thi cua
        // PHP. Dat gioi han PHP CAO HON timeout cua Process de khi qua gio thi
        // Symfony nem ProcessTimedOutException — mot ngoai le BAT DUOC, di qua
        // `catch` roi bao loi tu te — thay vi PHP chet dung giua chung.
        @ini_set('max_execution_time', (string) (self::RENDER_SECONDS + 60));
        @set_time_limit(self::RENDER_SECONDS + 60);

        [$image, $imageClaim, $reason] = $this->queue->claimForDirectRender($imageId, self::RENDER_SECONDS);

        if ($imageClaim === null) {
            return [$image, $reason];
        }

        $render = null;
        $claim = null;

        // Tu day tro di o anh DANG bi giu lease. Moi loi — ke ca loi khi dung
        // chinh cau thong bao loi — deu phai di qua `abort()`, neu khong o se
        // nam lai o `rendering` va khong ai claim lai duoc nua.
        try {
            $root = (string) config('video.runner.artifact_root');

            if ($root === '') {
                return $this->abort($imageId, $imageClaim, null, 'video.runner.artifact_root chua duoc cau hinh.');
            }

            // MOT render, MOT id. `verifyManifest()` doi manifest.render_id trung
            // `video_renders.id`, ma manifest lai mang id cua RenderRequest — nen
            // hai ben phai dung chung mot uuid quyet dinh ngay tu day.
            $renderId = (string) Str::uuid();

            [$prepared, $prepareError] = $this->prepare($image, $renderId);

            if ($prepared === null) {
                return $this->abort($imageId, $imageClaim, null, $prepareError);
            }

            $render = $this->dispatch->create(
                sessionId: null,
                assetId: $prepared['asset_id'],
                designImageId: $image->id,
                renderId: $renderId,
                provider: $prepared['provider'],
                model: $prepared['model'],
                idempotencyKey: $this->idempotencyKey($image),
                requestJson: $prepared['request_json'],
                requestHash: $prepared['request_hash'],
                canonicalRevisionId: $prepared['source_revision_id'],
                canonicalHash: $prepared['canonical_hash'],
                projectionHash: $prepared['projection_hash'],
                constraintSetHash: $prepared['constraint_set_hash'],
                promptSpecHash: $prepared['prompt_spec_hash'],
                providerPromptPlanHash: $prepared['provider_prompt_plan_hash'],
                promptHash: $prepared['prompt_hash'],
            );

            $claim = $this->claims->claimById($render->id, self::WORKER_ID);

            if ($claim === null) {
                return $this->abort(
                    $imageId,
                    $imageClaim,
                    $render,
                    'Khong claim duoc render vua tao, trang thai dang la: '.$render->execution_status?->value,
                );
            }

            $this->attempts->markPreparing($render->id, $claim->claimToken);
            $this->attempts->markSubmitting($render->id, $claim->claimToken);

            $result = $this->runProvider($claim, $root);

            $this->attempts->markSubmitted($render->id, $claim->claimToken, $result['provider_request_id'] ?? null);

            $this->checkpoints->complete(
                $render->id,
                $claim->claimToken,
                $claim->requestHash,
                $result['provider_request_id'] ?? null,
                $result['manifest'],
                RenderAttemptUsage::fromArray($result['usage'] ?? []),
            );
        } catch (Throwable $e) {
            if ($render !== null && $claim !== null) {
                $this->failures->fail(
                    $render->id,
                    $claim->claimToken,
                    RenderFailureClass::INTERNAL,
                    'canonical_render_failed',
                    mb_substr($e->getMessage(), 0, 500),
                );
            }

            return $this->abort($imageId, $imageClaim, $render, $e->getMessage());
        }

        [$done, $recordReason] = $this->queue->recordCanonicalResult(
            $imageId,
            $imageClaim,
            true,
            null,
            $render,
            $this->images($result['manifest'], $root),
        );

        return $done === null ? [$image, $recordReason] : [$done, 'rendered'];
    }

    /**
     * `manifest.json` tren dia LA bien nhan cua mot luot da goi provider va da
     * tra tien; duong toi no suy ra duoc tu chinh hang DB. Nen mot request chet
     * giua chung khong lam mat luot render — lan chay sau doi chieu voi dia roi
     * dong so, khong ton them dong nao.
     *
     * @return int so luot vua duoc dong so
     */
    public function reconcile(string $projectId): int
    {
        $root = (string) config('video.runner.artifact_root');

        if ($root === '' || $projectId === '') {
            return 0;
        }

        $pending = VideoRender::query()
            ->whereNotNull('design_image_id')
            ->whereNotNull('request_hash')
            ->whereNotIn('execution_status', [
                RenderStatus::SUCCEEDED->value,
                RenderStatus::FAILED->value,
                RenderStatus::CANCELLED->value,
            ])
            ->whereIn(
                'design_image_id',
                VideoDesignImage::query()->where('project_id', $projectId)->select('id'),
            )
            ->get();

        $settled = 0;

        foreach ($pending as $render) {
            if ($this->settle($render, $root)) {
                $settled++;
            }
        }

        return $settled;
    }

    private function settle(VideoRender $render, string $root): bool
    {
        $path = $this->manifestPath($render, $root);

        // Chua co manifest: hoac mot request khac dang bay, hoac Python chet
        // truoc khi provider tra ket qua. Ca hai deu chua ket luan duoc gi.
        if ($path === null || ! is_file($path)) {
            return false;
        }

        $manifest = json_decode((string) file_get_contents($path), true);

        if (! is_array($manifest)) {
            return false;
        }

        try {
            if ($render->execution_status !== RenderStatus::SUCCEEDED) {
                $this->checkpoints->complete(
                    $render->id,
                    (string) $render->claim_token,
                    (string) $render->request_hash,
                    $manifest['provider_request_id'] ?? null,
                    $manifest,
                    RenderAttemptUsage::fromArray($manifest['usage'] ?? []),
                );

                $render->refresh();
            }
        } catch (Throwable $e) {
            Log::warning('CanonicalImageRenderer: hoa giai that bai', [
                'render_id' => $render->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        [$done] = $this->queue->recordCanonicalResult(
            (string) $render->design_image_id,
            null,
            true,
            null,
            $render,
            $this->images($manifest, $root),
        );

        return $done !== null;
    }

    /**
     * `session_code` va `run_id` nam trong chinh `render_request_json`, con
     * `render_id` la khoa chinh cua hang. Nen khong can luu them gi de tim lai
     * artifact — hang DB tu no da du.
     */
    private function manifestPath(VideoRender $render, string $root): ?string
    {
        $payload = json_decode((string) $render->render_request_json, true);

        if (! is_array($payload) || ! isset($payload['session_code'], $payload['run_id'])) {
            return null;
        }

        return sprintf(
            '%s/%s/%s/renders/%s/attempt_%03d/manifest.json',
            rtrim(str_replace('\\', '/', $root), '/'),
            $payload['session_code'],
            $payload['run_id'],
            $render->id,
            max(1, (int) $render->attempt_count),
        );
    }

    /**
     * Khoa idempotency chan submit trung do nham lan, KHONG duoc chan mot luot
     * thu lai co chu y. Dem TAT CA hang render cua o — khong loc trang thai —
     * vi mot hang ket giua chung (`submitting`) cung khong claim lai duoc, y
     * het mot hang `failed`.
     */
    private function idempotencyKey(VideoDesignImage $image): string
    {
        $attempts = VideoRender::query()->where('design_image_id', $image->id)->count();

        return $attempts === 0 ? $image->id : $image->id.':r'.$attempts;
    }

    /**
     * Python lap RenderRequest chu khong phai PHP: hinh dang 29 truong cua no la
     * hop dong cua Phan 13, dung lai o day la chep lai mot lan nua.
     *
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    private function prepare(VideoDesignImage $image, string $renderId): array
    {
        $spec = $image->prompt_spec_json ?? [];
        $lineage = $spec['lineage'] ?? null;

        if (! is_array($lineage) || ! isset($lineage['source_revision_id'], $lineage['prompt_hash'])) {
            return [null, 'O anh nay duoc tao truoc khi lineage duoc luu — bam Compile Prompt roi Generate lai.'];
        }

        [$width, $height] = array_pad(array_map('intval', explode('x', (string) ($spec['size'] ?? ''))), 2, 0);
        $variations = max(1, (int) ($spec['variations'] ?? 1));

        $payload = [
            'render_id' => $renderId,
            'idempotency_key' => $image->id,
            'session_code' => $image->project_id,
            'run_id' => (string) $lineage['source_revision_id'],
            'asset_id' => 'master_'.($spec['viewpoint'] ?? ''),
            'width' => $width,
            'height' => $height,
            'quality' => ImageQuality::fromSpecOrHigh((string) ($spec['quality'] ?? ''))->value,
            'output_format' => 'png',
            'prompt' => (string) ($spec['prompt'] ?? ''),
            'negative_prompt' => $spec['negative_prompt'] ?? null,
            'native_controls' => $spec['native_controls'] ?? [],
            'provider_options' => $variations > 1 ? ['n' => $variations] : [],
            'lineage' => $lineage,
        ];

        [$ran, $output] = $this->pythonRunner->runAndWait(
            self::PREPARE_SCRIPT,
            ['--spec-file='.$this->writeJson('render_spec', $payload)],
            self::PREPARE_SECONDS,
        );

        $decoded = $this->readJson($output);

        if (! $ran || $decoded === null || ($decoded['ok'] ?? false) !== true) {
            return [null, (string) ($decoded['error'] ?? 'Khong doc duoc ket qua tu '.self::PREPARE_SCRIPT.': '.Str::limit($output, 300))];
        }

        return [$decoded + [
            'asset_id' => $payload['asset_id'],
            'provider' => (string) $lineage['provider'],
            'model' => (string) $lineage['model'],
            'source_revision_id' => (string) $lineage['source_revision_id'],
            'canonical_hash' => (string) $lineage['canonical_hash'],
            'projection_hash' => (string) $lineage['projection_hash'],
            'constraint_set_hash' => (string) $lineage['constraint_set_hash'],
            'prompt_spec_hash' => (string) $lineage['prompt_spec_hash'],
            'provider_prompt_plan_hash' => (string) $lineage['provider_prompt_plan_hash'],
            'prompt_hash' => (string) $lineage['prompt_hash'],
        ], 'ok'];
    }

    /**
     * @return array<string, mixed>
     */
    private function runProvider(RenderClaim $claim, string $root): array
    {
        $file = $this->writeRaw('render_request', $claim->renderRequestJson);

        try {
            [$ran, $output] = $this->pythonRunner->runAndWait(self::RENDER_SCRIPT, [
                '--request-file='.$file,
                '--artifact-root='.$root,
                '--attempt-no='.$claim->attemptNo,
                '--expected-hash='.$claim->requestHash,
            ], self::RENDER_SECONDS);
        } finally {
            @unlink($file);
        }

        $decoded = $this->readJson($output);

        if (! $ran || $decoded === null || ($decoded['ok'] ?? false) !== true) {
            // Provider co the da tinh tien roi moi den luot ta doc hong ket qua,
            // nen nguyen van stdout la thu duy nhat noi duoc chuyen gi da xay ra.
            Log::warning('CanonicalImageRenderer: khong doc duoc ket qua', [
                'render_id' => $claim->renderId,
                'output' => mb_substr($output, -2000),
            ]);

            throw new RuntimeException((string) ($decoded['error'] ?? Str::limit($output, 400)));
        }

        return $decoded;
    }

    /**
     * `manifest.artifacts[].path` la duong dan TUYET DOI do ArtifactWriter tra ve;
     * `video_artifacts.storage_path` luu duong dan TUONG DOI trong disk.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<array<string, mixed>>
     */
    private function images(array $manifest, string $root): array
    {
        $prefix = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/').'/';
        $images = [];

        foreach ($manifest['artifacts'] ?? [] as $artifact) {
            if (($artifact['kind'] ?? null) !== 'generated_image') {
                continue;
            }

            $path = str_replace('\\', '/', (string) $artifact['path']);

            $images[] = [
                'storage_disk' => 'video_artifacts',
                'storage_path' => str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path,
                'mime_type' => $artifact['mime_type'] ?? 'image/png',
                'file_size' => $artifact['size_bytes'] ?? null,
                'sha256' => (string) $artifact['sha256'],
            ];
        }

        return $images;
    }

    /**
     * @return array{0: ?VideoDesignImage, 1: string}
     */
    private function abort(string $imageId, string $imageClaim, ?VideoRender $render, ?string $error): array
    {
        [$done, $reason] = $this->queue->recordCanonicalResult($imageId, $imageClaim, false, $error, $render, []);

        return $done === null ? [null, $reason] : [$done, 'failed'];
    }

    /** @param array<string, mixed> $value */
    private function writeJson(string $prefix, array $value): string
    {
        return $this->writeRaw($prefix, json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function writeRaw(string $prefix, string $contents): string
    {
        $dir = rtrim((string) config('video.runner.log_dir'), '/\\');

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $path = $dir.DIRECTORY_SEPARATOR.$prefix.'_'.Str::random(8).'.json';
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @return ?array<string, mixed>
     */
    private function readJson(string $output): ?array
    {
        foreach (array_reverse(array_filter(array_map('trim', explode("\n", $output)))) as $line) {
            if (! str_starts_with($line, '{')) {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded) && array_key_exists('ok', $decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
