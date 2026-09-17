<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Video\Media\MediaModelRegistry;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class GeminiImageClient
{
    private const ASPECT_TOLERANCE = 0.01;

    /** @var list<string> */
    private const ALLOWED_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    /** @var list<string> */
    private const PNG_FUNCTIONS = [
        'imagecreatefromstring', 'imagepng', 'imagepalettetotruecolor', 'imagesavealpha',
    ];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly FilesystemFactory $storage,
        private readonly MediaModelRegistry $registry,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $disk,
        private readonly string $memoryLimit,
        private readonly int $timeoutSeconds,
        /** @var list<string> */
        private readonly array $pngFunctions = self::PNG_FUNCTIONS,
    ) {}

    /**
     * @param  array<string, mixed>  $spec
     * @return array{ok: bool, error: ?string, renders: list<array<string, mixed>>}
     */
    public function generate(array $spec, int $budgetSeconds): array
    {
        if (trim($this->apiKey) === '') {
            return $this->fail('GEMINI_API_KEY chua cau hinh — khong gui request.');
        }

        // Task doc tu spec DA CHUAN HOA, khong suy lai tu `operation`. Lop nay chi
        // duoc goi sau `DesignImageDirectRenderer::spec()`, noi task da duoc quyet
        // dinh — suy lai o day la cho mot task sai bi loai o buoc truoc co co hoi
        // song lai thanh mot task dung.
        $task = $spec['task'] ?? null;

        if (! is_string($task) || $task === '') {
            return $this->fail('spec khong mang task — khong gui request');
        }

        try {
            $entry = $this->registry->find($task, 'gemini:'.(string) ($spec['model'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return $this->fail('Registry model hong — khong gui request: '.$e->getMessage());
        }

        $missing = $this->missingFunction($this->pngFunctions);

        $reason = match (true) {
            $missing !== null => 'may nay thieu ham GD '.$missing.' nen khong ghi duoc PNG',
            $entry === null => 'model Gemini khong co trong registry: '.($spec['model'] ?? ''),
            trim((string) ($spec['prompt'] ?? '')) === '' => 'prompt rong',
            ($spec['shape'] ?? null) !== $entry['shape'] => 'shape khong khop registry',
            ($spec['api_version'] ?? null) !== $entry['api_version'] => 'api_version khong khop registry',
            ! in_array($spec['aspect_ratio'] ?? null, $entry['controls']['aspect_ratios'], true) => 'aspect_ratio ngoai registry',
            ! in_array($spec['image_size'] ?? null, $entry['controls']['image_sizes'], true) => 'image_size ngoai registry',
            (int) ($spec['variations'] ?? 1) !== 1 => 'Gemini chi sinh 1 anh moi luot',
            default => null,
        };

        if ($reason !== null) {
            return $this->fail($reason.' — khong gui request.');
        }

        $payload = [
            'contents' => [['parts' => [['text' => (string) $spec['prompt']]]]],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
                'imageConfig' => [
                    'aspectRatio' => (string) $spec['aspect_ratio'],
                    'imageSize' => (string) $spec['image_size'],
                ],
            ],
        ];

        $this->extendPhpLimits($budgetSeconds);
        $startedAt = microtime(true);

        try {
            $response = $this->http
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->timeout($this->timeoutSeconds)
                ->retry(2, 1000, when: static fn (Throwable $e): bool => $e instanceof RequestException
                    && $e->response->status() === 429, throw: false)
                ->post(sprintf(
                    '%s/%s/models/%s:generateContent',
                    rtrim($this->baseUrl, '/'),
                    $entry['api_version'],
                    $entry['model'],
                ), $payload);
        } catch (ConnectionException $e) {
            return $this->fail(sprintf(
                'Khong ket noi duoc Gemini sau %ds: %s. Neu la timeout thi anh CO THE da duoc sinh.',
                $this->timeoutSeconds,
                Str::limit($e->getMessage(), 200),
            ));
        }

        return $this->parse($response, $spec, $payload, $startedAt);
    }

    /**
     * Kiem MOI phan anh truoc, chi ghi file khi co dung mot anh hop le: Gemini
     * duoc yeu cau sinh mot anh, nen hai anh la mot ket qua khong dung spec.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error: ?string, renders: list<array<string, mixed>>}
     */
    private function parse(Response $response, array $spec, array $payload, float $startedAt): array
    {
        if ($response->failed()) {
            return $this->fail(sprintf(
                'Gemini %d: %s',
                $response->status(),
                Str::limit((string) ($response->json('error.message') ?? $response->body()), 300),
            ));
        }

        $blocked = $response->json('promptFeedback.blockReason');

        if (is_string($blocked) && $blocked !== '') {
            return $this->fail('Gemini chan prompt: '.$blocked);
        }

        $valid = [];
        $rejected = [];

        foreach ((array) $response->json('candidates', []) as $candidate) {
            foreach ((array) (is_array($candidate) ? ($candidate['content']['parts'] ?? []) : []) as $part) {
                $inline = is_array($part) ? ($part['inlineData'] ?? $part['inline_data'] ?? null) : null;

                if (! is_array($inline)) {
                    continue;
                }

                $declared = (string) ($inline['mimeType'] ?? $inline['mime_type'] ?? '');
                $bytes = base64_decode((string) ($inline['data'] ?? ''), true);

                if ($bytes === false || $bytes === '') {
                    $rejected[] = 'base64 hong ('.$declared.')';

                    continue;
                }

                $info = @getimagesizefromstring($bytes);
                $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';

                if ($mime === '' || ! in_array($mime, self::ALLOWED_MIMES, true)) {
                    $rejected[] = 'khong phai anh cho phep (khai '.$declared.', that la '.($mime !== '' ? $mime : 'khong doc duoc').')';

                    continue;
                }

                $valid[] = [
                    'bytes' => $bytes,
                    'mime' => $mime,
                    'declared' => $declared,
                    'width' => (int) $info[0],
                    'height' => (int) $info[1],
                ];
            }
        }

        if (count($valid) !== 1) {
            return $this->fail(match (true) {
                $valid !== [] => 'Gemini tra '.count($valid).' anh trong khi chi yeu cau 1 — khong ghi file nao.',
                $rejected !== [] => 'Gemini tra 200 nhung khong co anh hop le: '.implode('; ', $rejected),
                default => 'Gemini tra 200 nhung khong co anh nao (finishReason: '
                    .(string) $response->json('candidates.0.finishReason', 'unknown').').',
            });
        }

        $image = $valid[0];
        [$bytes, $converted] = $this->asPng($image);

        if ($bytes === null) {
            return $this->fail('Khong chuyen duoc anh '.$image['mime'].' sang PNG — khong ghi file nao.');
        }

        $dir = $spec['project_id'].'/'.$spec['image_id'].'/renders/'.$spec['claim_token'];
        $path = $dir.'/output_000.png';

        if (! $this->storage->disk($this->disk)->put($path, $bytes)) {
            Log::warning('gemini-image: khong ghi duoc file', [
                'image_id' => $spec['image_id'],
                'disk' => $this->disk,
                'path' => $path,
            ]);

            return $this->fail('Khong ghi duoc file anh Gemini.');
        }

        return ['ok' => true, 'error' => null, 'renders' => [[
            'idempotency_key' => $spec['claim_token'].':0',
            'storage_disk' => $this->disk,
            'storage_path' => $path,
            'artifact_sha256' => hash('sha256', $bytes),
            'mime_type' => 'image/png',
            'width' => $image['width'],
            'height' => $image['height'],
            'bytes' => strlen($bytes),
            'cost' => null,
            'pricing' => 'unpriced',
            'provider_request_id' => $response->json('responseId'),
            'provider_usage' => $response->json('usageMetadata'),
            'render' => [
                'provider' => 'gemini',
                'model' => (string) $spec['model'],
                'render_kind' => (string) $spec['operation'],
                'sent_prompt' => (string) $spec['prompt'],
                'source_kind' => 'text',
                'artifact_dir' => $dir,
                'provider_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'request_sha256' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'request_json' => $this->observation($spec, $image) + [
                    'bytes_received' => strlen($image['bytes']),
                    'converted_to_png' => $converted,
                ],
            ],
        ]]];
    }

    /**
     * Chinh sach "moi anh luu ra deu la PNG" bien GD thanh dieu kien can cua mot
     * lan goi co tra tien: thieu ham thi phai biet TRUOC khi gui request.
     *
     * @param  list<string>  $functions
     */
    private function missingFunction(array $functions): ?string
    {
        foreach ($functions as $function) {
            if (! function_exists($function)) {
                return $function;
            }
        }

        return null;
    }

    /**
     * Gemini tra JPEG, ca chuoi dung hinh con lai chay bang PNG. Ma hoa lai KHONG
     * lam anh net hon — no chan mat mat o nhung lan sua anh sau, va giu mot dinh
     * dang duy nhat cho ca duong render.
     *
     * @param  array{bytes: string, mime: string}  $image
     * @return array{0: ?string, 1: bool} [$bytes, $converted]
     */
    private function asPng(array $image): array
    {
        if ($image['mime'] === 'image/png') {
            return [$image['bytes'], false];
        }

        $canvas = @imagecreatefromstring($image['bytes']);

        if ($canvas === false) {
            return [null, true];
        }

        try {
            imagepalettetotruecolor($canvas);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);

            ob_start();
            $encoded = imagepng($canvas);
            $png = (string) ob_get_clean();
        } finally {
            imagedestroy($canvas);
        }

        return [$encoded && $png !== '' ? $png : null, true];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array{mime: string, declared: string, width: int, height: int}  $image
     * @return array<string, mixed>
     */
    private function observation(array $spec, array $image): array
    {
        $parts = explode(':', (string) $spec['aspect_ratio']);
        $w = (int) ($parts[0] ?? 0);
        $h = (int) ($parts[1] ?? 0);
        $requested = $h > 0 ? $w / $h : null;
        $observed = $image['height'] > 0 ? $image['width'] / $image['height'] : null;

        return [
            'api_version' => $spec['api_version'],
            'shape' => $spec['shape'],
            'aspect_ratio_requested' => $spec['aspect_ratio'],
            'image_size_requested' => $spec['image_size'],
            'observed_width' => $image['width'],
            'observed_height' => $image['height'],
            'aspect_observed' => $observed === null ? null : round($observed, 4),
            'aspect_mismatch' => $requested !== null && $observed !== null
                && abs($observed - $requested) / $requested > self::ASPECT_TOLERANCE,
            'mime_declared' => $image['declared'],
            'mime_detected' => $image['mime'],
        ];
    }

    /** @return array{ok: bool, error: string, renders: list<array<string, mixed>>} */
    private function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'renders' => []];
    }

    private function extendPhpLimits(int $budgetSeconds): void
    {
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', (string) $budgetSeconds);
            @ini_set('memory_limit', $this->memoryLimit);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit($budgetSeconds);
        }
    }
}
