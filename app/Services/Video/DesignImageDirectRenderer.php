<?php

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Enums\ImageQuality;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoRender;
use App\Video\Media\RenderPriceSnapshot;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Duong render dong bo: Laravel giu o, goi thang OpenAI, tu ghi so cai.
 *
 * Cung hinh dang voi duong Haiku/Sonnet dang chay: claim + lease NGAY TREN HANG
 * DU LIEU roi moi goi provider. Boc mot Job ra ngoai sau nay khong phai sua gi
 * ben trong.
 *
 * Ba chot giu tien:
 *   1. dedupe theo prompt hash o `DesignImageStore::createCandidate()`
 *   2. `claimForDirectRender()` — bam lan hai trong luc render gap `rendering`
 *   3. claim token kiem lai truoc khi ghi so cai
 *
 * Con thieu so voi duong hang doi: khong co outbox. Tien trinh chet SAU khi
 * provider da tinh tien thi khong co gi phat lai.
 */
class DesignImageDirectRenderer
{
    /** @var array<string, string> */
    private const SOURCE_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private DesignImageQueue $queue,
        private OpenAiImageClient $client,
        private FilesystemFactory $storage,
        private GeminiImageClient $gemini,
        private RenderPriceSnapshot $prices = new RenderPriceSnapshot,
    ) {}

    /**
     * @param  list<string>|null  $acceptStatuses
     * @return array{0: ?VideoDesignImage, 1: string} [$image, $reason]
     *                                                reason: rendered|failed|not_enqueueable|image_not_found|<chan doan>
     */
    public function renderNow(string $imageId, ?array $acceptStatuses = null): array
    {
        $budget = $this->budgetSeconds();

        [$image, $claimToken, $reason] = $this->queue->claimForDirectRender(
            $imageId, $budget, $acceptStatuses,
        );

        if ($claimToken === null) {
            return [$image, $reason];
        }

        /*
         * Mot ngoai le thoat ra khoi day la claim treo toi het lease va so cai
         * khong co dong nao — trong khi provider co the da tinh tien. Nen moi
         * duong ra deu phai di qua `recordDirectResult()`.
         */
        try {
            $spec = $this->spec($image, $image->prompt_spec_json ?? [], $claimToken);

            if (! in_array($spec['provider'], ['openai', 'gemini'], true)) {
                throw new RuntimeException('Provider chua co client: '.$spec['provider']);
            }

            if ($spec['provider'] === 'gemini' && $spec['operation'] !== 'environment_plate') {
                throw new RuntimeException('Gemini chi duoc dung cho environment_plate, khong cho '.$spec['operation']);
            }

            // O khai co gia ma khong mang duoc DU snapshot thi day la hang hong: mot
            // lan goi co tra tien khong duoc phep di ma sau nay khong doi soat duoc.
            //
            // Ap cho MOI provider, ke ca o tao truoc Pha 3C: hang cu van doc duoc va
            // van hien dung tren man hinh, nhung khong duoc dung de mua them mot luot
            // render nua.
            if ($spec['pricing'] === 'estimated'
                && ($spec['unit_cost_usd'] === null || $spec['pricing_version'] === '')) {
                throw new RuntimeException(
                    'O khai estimated nhung snapshot gia khong day du — khong gui request.',
                );
            }

            $result = match ($spec['operation']) {
                'mirror' => $this->mirror($image, $spec, $claimToken),
                'edit' => $this->client->edit($spec, $this->sourceBytes($image, $spec), 'approved-anchor.png', $budget),
                'scene_keyframe' => $this->sendManifest($spec, $this->manifestBytes($image, $spec), $budget),
                'environment_plate' => $this->environmentPlate($spec, $budget),
                'generate' => $this->client->generate($spec, $budget),
                default => throw new RuntimeException(
                    'Unknown render operation: '.$spec['operation'],
                ),
            };

            $result['renders'] = $this->prices->applyTo($spec, $result['renders']);
        } catch (Throwable $e) {
            Log::error('DesignImageDirectRenderer: client nem, nha claim', [
                'image_id' => $imageId,
                'exception' => $e,
            ]);

            $result = [
                'ok' => false,
                'error' => get_class($e).': '.$e->getMessage(),
                'renders' => [],
            ];
        }

        [$done, $recordReason] = $this->queue->recordDirectResult(
            $imageId,
            $claimToken,
            $result['ok'],
            $result['error'],
            $result['renders'],
        );

        if ($done === null) {
            return [$image, $recordReason];
        }

        return [$done, $done->status === DesignImageStatus::RENDERED->value ? 'rendered' : 'failed'];
    }

    private function budgetSeconds(): int
    {
        return max(
            (int) config('video.openai_image.timeout', 300),
            (int) config('video.gemini.image_timeout', 300),
        ) + 60;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function spec(VideoDesignImage $image, array $spec, string $claimToken): array
    {
        $provider = (string) ($spec['provider'] ?? 'openai');
        $pricing = (string) ($spec['pricing'] ?? 'estimated');
        $quality = $provider === 'openai' ? ImageQuality::fromSpecOrHigh($spec['quality'] ?? '') : null;
        $unit = $this->unitCost($spec, $pricing, $provider, $quality);

        return [
            'image_id' => $image->id,
            'project_id' => $image->project_id,
            'claim_token' => $claimToken,
            'prompt' => (string) ($spec['prompt'] ?? ''),
            'operation' => (string) ($spec['operation'] ?? 'generate'),
            'provider' => $provider,
            'model' => (string) ($spec['model'] ?? ''),
            'quality' => $quality?->value,
            'size' => (string) ($spec['size'] ?? ''),
            'variations' => (int) ($spec['variations'] ?? 1),
            'pricing' => $pricing,
            'unit_cost_usd' => $unit,
            // MOI ANH, khong phai ca o: client gan so nay vao tung render item, va
            // queue ghi mot dong so cai cho moi item. Nhan voi variations o day la
            // dem tien hai lan.
            'cost_estimate' => $unit,
            'pricing_version' => (string) ($spec['pricing_version'] ?? ''),
            'pricing_backfilled_at' => (string) ($spec['pricing_backfilled_at'] ?? ''),
            'api_version' => (string) ($spec['api_version'] ?? ''),
            'shape' => (string) ($spec['shape'] ?? ''),
            'aspect_ratio' => (string) ($spec['aspect_ratio'] ?? ''),
            'image_size' => (string) ($spec['image_size'] ?? ''),
            'source_artifact_id' => $spec['source_artifact_id'] ?? null,
            'source_artifact_sha256' => (string) ($spec['source_artifact_sha256'] ?? ''),
            'derivation_version' => (string) ($spec['derivation_version'] ?? ''),
            'render_scene_id' => $spec['render_scene_id'] ?? null,
            'environment_key' => $spec['environment_key'] ?? null,
            'reference_manifest_hash' => (string) ($spec['reference_manifest_hash'] ?? ''),
            'sources' => is_array($spec['sources'] ?? null) ? $spec['sources'] : [],
        ];
    }


    /**
     * Gia da dong bang luc tao o, khong phai gia hom nay: mot o render thang truoc
     * phai giu nguyen con so no da duoc bao truoc khi bam.
     *
     * @param  array<string, mixed>  $spec
     */
    private function unitCost(array $spec, string $pricing, string $provider, ?ImageQuality $quality): ?float
    {
        if ($pricing === 'unpriced') {
            return null;
        }

        $frozen = $spec['unit_cost_usd'] ?? null;

        if (is_int($frozen) || is_float($frozen)) {
            $frozen = (float) $frozen;

            return $frozen > 0 && is_finite($frozen) ? $frozen : null;
        }

        // Hang OpenAI cu chua co gia dong bang — bang gia theo quality van dung.
        return $provider === 'openai' && $quality !== null ? $quality->estimatedCostUsd() : null;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{ok: bool, error: ?string, renders: list<array<string, mixed>>}
     */
    private function environmentPlate(array $spec, int $budget): array
    {
        return $spec['provider'] === 'gemini'
            ? $this->gemini->generate($spec, $budget)
            : $this->client->generate($spec, $budget);
    }

    /**
     * Keyframe LUON dung mot anh nguon, va no di truong `image` — dang day da
     * duoc chung minh bang tien that o chuoi dung hinh.
     *
     * @param  array<string, mixed>  $spec
     * @param  list<array{bytes: string, filename: string}>  $images
     * @return array{ok: bool, error: ?string, renders: list<array<string, mixed>>}
     */
    private function sendManifest(array $spec, array $images, int $budget): array
    {
        if ($images === [] || count($images) > OpenAiImageClient::MAX_SOURCE_IMAGES) {
            throw new RuntimeException('Scene keyframe manifest is outside the source cap.');
        }

        return count($images) === 1
            ? $this->client->edit($spec, $images[0]['bytes'], $images[0]['filename'], $budget)
            : $this->client->editWithSources($spec, $images, $budget);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<array{bytes: string, filename: string}>
     */
    private function manifestBytes(VideoDesignImage $image, array $spec): array
    {
        $sources = $spec['sources'] ?? [];

        if (! is_array($sources) || ! array_is_list($sources) || $sources === []) {
            throw new RuntimeException('Scene keyframe spec carries no source manifest.');
        }

        $images = [];

        foreach ($sources as $position => $entry) {
            if (! is_array($entry) || ($entry['position'] ?? null) !== $position) {
                throw new RuntimeException('Scene keyframe manifest is out of order.');
            }

            $artifact = VideoArtifact::query()
                ->whereKey($entry['artifact_id'] ?? null)
                ->where('project_id', $image->project_id)
                ->first();

            if ($artifact === null
                || (string) $artifact->design_image_id !== (string) ($entry['candidate_id'] ?? '')) {
                throw new RuntimeException('Scene keyframe source no longer belongs to its candidate.');
            }

            $images[] = [
                'bytes' => $this->verifiedBytes($artifact, [
                    'source_artifact_sha256' => $entry['sha256'] ?? '',
                ]),
                'filename' => 'source_'.str_pad((string) $position, 2, '0', STR_PAD_LEFT)
                    .'.'.$this->sourceExtension($artifact),
            ];
        }

        return $images;
    }

    /**
     * Duoi tep quyet dinh Content-Type cua phan multipart, nen no phai theo mime
     * that cua artifact: Gemini tra JPEG, OpenAI tra PNG.
     */
    private function sourceExtension(VideoArtifact $artifact): string
    {
        $mime = (string) $artifact->mime_type;

        if (! array_key_exists($mime, self::SOURCE_EXTENSIONS)) {
            throw new RuntimeException('Scene keyframe source carries an unsupported mime: '.$mime);
        }

        return self::SOURCE_EXTENSIONS[$mime];
    }

    /** @param array<string, mixed> $spec */
    private function sourceArtifact(VideoDesignImage $image, array $spec): VideoArtifact
    {
        $artifact = VideoArtifact::query()
            ->whereKey($spec['source_artifact_id'] ?? null)
            ->where('project_id', $image->project_id)
            ->first();

        if ($artifact === null) {
            throw new RuntimeException('Reference source artifact does not belong to project.');
        }

        return $artifact;
    }

    /** @param array<string, mixed> $spec */
    private function sourceBytes(VideoDesignImage $image, array $spec): string
    {
        return $this->verifiedBytes($this->sourceArtifact($image, $spec), $spec);
    }

    /** @param array<string, mixed> $spec */
    private function verifiedBytes(VideoArtifact $artifact, array $spec): string
    {
        $bytes = $this->storage
            ->disk((string) $artifact->storage_disk)
            ->get((string) $artifact->storage_path);

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Reference source artifact file is missing.');
        }

        $expectedSha = trim((string) ($spec['source_artifact_sha256'] ?? ''));

        if ($expectedSha === '' || ! hash_equals($expectedSha, hash('sha256', $bytes))) {
            throw new RuntimeException('Reference source artifact checksum mismatch.');
        }

        return $bytes;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{ok: bool, error: ?string, renders: list<array<string, mixed>>}
     */
    private function mirror(VideoDesignImage $image, array $spec, string $claimToken): array
    {
        $artifact = $this->sourceArtifact($image, $spec);

        if ($artifact->render_id === null) {
            throw new RuntimeException('Reference source artifact has no render row to trace back to.');
        }

        $sourceRender = VideoRender::query()->whereKey($artifact->render_id)->first();

        if ($sourceRender === null || $sourceRender->status !== 'succeeded') {
            throw new RuntimeException('Reference source render did not succeed.');
        }

        $bytes = $this->verifiedBytes($artifact, $spec);

        @ini_set('memory_limit', (string) config('video.openai_image.memory_limit', '1024M'));
        $startedAt = microtime(true);

        $canvas = @imagecreatefromstring($bytes);

        if ($canvas === false) {
            throw new RuntimeException('Reference source artifact is not a readable image.');
        }

        try {
            imageflip($canvas, IMG_FLIP_HORIZONTAL);

            ob_start();
            imagepng($canvas);
            $flipped = (string) ob_get_clean();
            $width = imagesx($canvas);
            $height = imagesy($canvas);
        } finally {
            imagedestroy($canvas);
        }

        if ($flipped === '') {
            throw new RuntimeException('Mirrored image could not be encoded.');
        }

        $disk = (string) config('video.openai_image.disk');
        $dir = $spec['project_id'].'/'.$spec['image_id'].'/renders/'.$claimToken;
        $path = $dir.'/output_000.png';

        if (! $this->storage->disk($disk)->put($path, $flipped)) {
            throw new RuntimeException('Mirrored image could not be written to disk.');
        }

        $request = [
            'derivation' => 'horizontal_flip',
            'derivation_version' => (string) ($spec['derivation_version'] ?? ''),
            'source_artifact_id' => $artifact->id,
            'source_artifact_sha256' => (string) $artifact->sha256,
        ];

        return [
            'ok' => true,
            'error' => null,
            'renders' => [[
                'idempotency_key' => $claimToken.':0',
                'storage_disk' => $disk,
                'storage_path' => $path,
                'artifact_sha256' => hash('sha256', $flipped),
                'mime_type' => 'image/png',
                'width' => $width,
                'height' => $height,
                'bytes' => strlen($flipped),
                'cost' => 0.0,
                'pricing' => 'free',
                'provider_request_id' => null,
                'provider_usage' => null,
                'render' => [
                    'provider' => 'local',
                    'model' => 'gd',
                    'render_kind' => 'reference_mirror',
                    'sent_prompt' => (string) $spec['prompt'],
                    'source_kind' => 'image',
                    'artifact_dir' => $dir,
                    'provider_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'request_sha256' => hash('sha256', json_encode($request, JSON_THROW_ON_ERROR)),
                    'source_render_id' => $sourceRender->id,
                    'request_json' => $request,
                ],
            ]],
        ];
    }
}
