<?php

declare(strict_types=1);

namespace App\Services\Video;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class OpenAiImageClient
{
    private const ENDPOINT = '/v1/images/generations';

    private const EDIT_ENDPOINT = '/v1/images/edits';

    private const MAX_PROMPT_CHARS = 32000;

    private const MAX_VARIATIONS = 10;

    public const MAX_SOURCE_IMAGES = 4;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly FilesystemFactory $storage,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $disk,
        private readonly string $memoryLimit,
        private readonly int $timeoutSeconds,
    ) {
        if (trim($this->apiKey) === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        if ($this->timeoutSeconds < 1) {
            throw new RuntimeException('OpenAI image timeout must be >= 1 second.');
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{ok: bool, error: ?string, renders: list<array<string, mixed>>}
     */
    public function generate(array $spec, int $budgetSeconds): array
    {
        $prompt = (string) ($spec['prompt'] ?? '');

        if (trim($prompt) === '') {
            return $this->fail('Spec khong co prompt.');
        }

        if (mb_strlen($prompt) > self::MAX_PROMPT_CHARS) {
            return $this->fail(sprintf(
                'Prompt %d ky tu, vuot tran %d cua GPT Image.',
                mb_strlen($prompt),
                self::MAX_PROMPT_CHARS,
            ));
        }

        $requested = max(1, min(self::MAX_VARIATIONS, (int) ($spec['variations'] ?? 1)));

        $payload = [
            'model' => (string) $spec['model'],
            'prompt' => $prompt,
            'n' => $requested,
            'size' => (string) $spec['size'],
            'quality' => (string) $spec['quality'],
            'output_format' => 'png',
        ];

        $this->extendPhpLimits($budgetSeconds);
        $startedAt = microtime(true);

        try {
            $response = $this->http
                ->withHeaders([
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'content-type' => 'application/json',
                ])
                ->timeout($this->timeoutSeconds)
                ->retry(
                    2,
                    1000,
                    when: static fn (Throwable $exception): bool => $exception instanceof RequestException
                        && $exception->response->status() === 429,
                    throw: false,
                )
                ->post(rtrim($this->baseUrl, '/').self::ENDPOINT, $payload);
        } catch (ConnectionException $exception) {
            return $this->connectionFail($exception);
        }

        return $this->parseImageResponse($response, $spec, $payload, $requested, $startedAt);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{ok: bool, error: ?string, renders: list<array<string, mixed>>}
     */
    public function edit(array $spec, string $imageBytes, string $filename, int $budgetSeconds): array
    {
        if ($imageBytes === '') {
            return $this->fail('Anh nguon rong.');
        }

        return $this->send(
            $spec,
            [['bytes' => $imageBytes, 'filename' => $filename]],
            $budgetSeconds,
            'image',
            ['source_artifact_sha256' => (string) ($spec['source_artifact_sha256'] ?? '')],
        );
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<array{bytes: string, filename: string}>  $images
     * @return array{ok: bool, error: ?string, renders: list<array<string, mixed>>}
     */
    public function editWithSources(array $spec, array $images, int $budgetSeconds): array
    {
        if ($images === []) {
            return $this->fail('Khong co anh nguon nao.');
        }

        if (count($images) > self::MAX_SOURCE_IMAGES) {
            return $this->fail(sprintf(
                'Gui %d anh nguon, vuot tran %d cua hop dong.',
                count($images),
                self::MAX_SOURCE_IMAGES,
            ));
        }

        foreach (array_values($images) as $position => $image) {
            if (! is_array($image)
                || ! is_string($image['bytes'] ?? null)
                || $image['bytes'] === '') {
                return $this->fail('Anh nguon o vi tri '.$position.' rong hoac sai kieu.');
            }

            if (! is_string($image['filename'] ?? null) || trim($image['filename']) === '') {
                return $this->fail('Ten tep cua anh nguon o vi tri '.$position.' khong hop le.');
            }
        }

        return $this->send(
            $spec,
            array_values($images),
            $budgetSeconds,
            'image[]',
            ['reference_manifest_hash' => (string) ($spec['reference_manifest_hash'] ?? '')],
        );
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<array{bytes: string, filename: string}>  $images
     * @param  array<string, mixed>  $extraPayload
     * @return array{ok: bool, error: ?string, renders: list<array<string, mixed>>}
     */
    private function send(
        array $spec,
        array $images,
        int $budgetSeconds,
        string $field,
        array $extraPayload,
    ): array {
        $prompt = trim((string) ($spec['prompt'] ?? ''));

        if ($prompt === '') {
            return $this->fail('Spec khong co prompt.');
        }

        if (mb_strlen($prompt) > self::MAX_PROMPT_CHARS) {
            return $this->fail(sprintf(
                'Prompt %d ky tu, vuot tran %d cua GPT Image.',
                mb_strlen($prompt),
                self::MAX_PROMPT_CHARS,
            ));
        }

        $requested = max(1, min(self::MAX_VARIATIONS, (int) ($spec['variations'] ?? 1)));

        $fields = [
            'model' => (string) $spec['model'],
            'prompt' => $prompt,
            'n' => (string) $requested,
            'size' => (string) $spec['size'],
            'quality' => (string) $spec['quality'],
        ];

        $this->extendPhpLimits($budgetSeconds);
        $startedAt = microtime(true);

        $request = $this->http
            ->withHeaders(['Authorization' => 'Bearer '.$this->apiKey])
            ->timeout($this->timeoutSeconds)
            ->retry(
                2,
                1000,
                when: static fn (Throwable $exception): bool => $exception instanceof RequestException
                    && $exception->response->status() === 429,
                throw: false,
            );

        foreach ($images as $image) {
            $request = $request->attach($field, $image['bytes'], $image['filename']);
        }

        try {
            $response = $request->post(rtrim($this->baseUrl, '/').self::EDIT_ENDPOINT, $fields);
        } catch (ConnectionException $exception) {
            return $this->connectionFail($exception);
        }

        return $this->parseImageResponse(
            $response,
            $spec,
            $fields + $extraPayload,
            $requested,
            $startedAt,
        );
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error: ?string, renders: list<array<string, mixed>>}
     */
    private function parseImageResponse(
        Response $response,
        array $spec,
        array $payload,
        int $requested,
        float $startedAt,
    ): array {
        if ($response->failed()) {
            $error = $response->json('error') ?? [];

            return $this->fail(sprintf(
                'OpenAI %d (%s): %s',
                $response->status(),
                (string) ($error['code'] ?? $error['type'] ?? 'unknown'),
                (string) ($error['message'] ?? mb_substr($response->body(), 0, 300)),
            ));
        }

        $providerMs = (int) round((microtime(true) - $startedAt) * 1000);
        $header = $response->header('x-request-id');
        $requestId = is_string($header) && trim($header) !== '' ? $header : null;

        $provider = [
            'usage' => $response->json('usage'),
            'size' => $response->json('size'),
            'quality' => $response->json('quality'),
            'output_format' => $response->json('output_format'),
            'created' => $response->json('created'),
        ];

        $filesystem = $this->storage->disk($this->disk);
        $dir = $spec['project_id'].'/'.$spec['image_id'].'/renders/'.$spec['claim_token'];
        $requestHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $prompt = (string) ($payload['prompt'] ?? '');
        $renders = [];

        foreach ((array) ($response->json('data') ?? []) as $index => $item) {
            $bytes = base64_decode((string) ($item['b64_json'] ?? ''), true);

            if ($bytes === false || $bytes === '') {
                Log::warning('openai-image: phan tu data khong giai ma duoc', [
                    'image_id' => $spec['image_id'],
                    'index' => $index,
                    'provider_request_id' => $requestId,
                ]);

                continue;
            }

            $path = $dir.'/output_'.str_pad((string) $index, 3, '0', STR_PAD_LEFT).'.png';

            if (! $filesystem->put($path, $bytes)) {
                Log::warning('openai-image: khong ghi duoc file, anh nay bi bo', [
                    'image_id' => $spec['image_id'],
                    'disk' => $this->disk,
                    'path' => $path,
                    'provider_request_id' => $requestId,
                ]);

                continue;
            }

            $dimensions = getimagesizefromstring($bytes) ?: [];

            $renders[] = [
                'idempotency_key' => $spec['claim_token'].':'.$index,
                'storage_disk' => $this->disk,
                'storage_path' => $path,
                'artifact_sha256' => hash('sha256', $bytes),
                'mime_type' => 'image/png',
                'width' => $dimensions[0] ?? null,
                'height' => $dimensions[1] ?? null,
                'bytes' => strlen($bytes),
                // KHONG dat uoc tinh vao day: `cost_usd` chi mang tien da xac nhan,
                // va OpenAI khong bao chi phi cho images API. Uoc tinh di duong
                // metadata (`estimated_cost_usd`).
                'cost' => null,
                'pricing' => (string) ($spec['pricing'] ?? 'estimated'),
                'provider_request_id' => $requestId,
                'provider_usage' => $provider,
                'render' => [
                    'provider' => 'openai',
                    'model' => (string) $spec['model'],
                    'render_kind' => (string) ($spec['operation'] ?? 'generate'),
                    'sent_prompt' => $prompt,
                    'source_kind' => in_array($spec['operation'] ?? 'generate', ['edit', 'scene_keyframe'], true)
                        ? 'image'
                        : 'text',
                    'artifact_dir' => $dir,
                    'provider_ms' => $providerMs,
                    'request_sha256' => $requestHash,
                ],
            ];

            unset($bytes);
        }

        if ($renders === []) {
            return $this->fail('OpenAI tra ve 200 nhung khong luu duoc anh nao.');
        }

        if (count($renders) !== $requested) {
            Log::warning('openai-image: so anh luu duoc it hon so da tra tien', [
                'image_id' => $spec['image_id'],
                'requested' => $requested,
                'stored' => count($renders),
                'provider_request_id' => $requestId,
            ]);
        }

        return ['ok' => true, 'error' => null, 'renders' => $renders];
    }

    /** @return array{ok: bool, error: string, renders: list<array<string, mixed>>} */
    private function connectionFail(ConnectionException $exception): array
    {
        return $this->fail(sprintf(
            'Khong ket noi duoc OpenAI sau %ds: %s. Neu la timeout thi anh CO THE da duoc sinh '
            .'— doi chieu bang usage truoc khi bam lai.',
            $this->timeoutSeconds,
            $exception->getMessage(),
        ));
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
