<?php

namespace App\Video\Media;

use App\Video\Render\Enums\RenderFailureClass;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Throwable;

final class GeminiOmniVideoClient
{
    public const PAYLOAD_VERSION = 'omni-reference-v1';

    private const FILE_PATTERN = '#/files/([A-Za-z0-9_-]+)#';

    private const OUTPUT_STEP = 'model_output';

    public const EXTEND_TASK = 'extend';

    private const SCRUB_FROM_CHARS = 256;

    /**
     * @param  list<string>  $downloadHosts
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly GeminiVeoVideoClient $files,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
        private readonly int $pollTimeoutSeconds,
        private readonly array $downloadHosts,
    ) {}

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<array{mime: string, base64: string}>  $images
     * @param  array{aspect_ratio: string, resolution: string}  $output
     * @return array<string, mixed>
     */
    public static function payload(array $entry, array $images, string $prompt, array $output): array
    {
        return self::request($entry, $images, $prompt, $output, (string) $entry['controls']['task']);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<array{mime: string, base64: string}>  $images
     * @param  array{aspect_ratio: string, resolution: string}  $output
     * @return array<string, mixed>
     */
    public static function extendPayload(array $entry, string $previousInteractionId, array $images, string $prompt, array $output): array
    {
        return ['previous_interaction_id' => $previousInteractionId] + self::request($entry, $images, $prompt, $output, self::EXTEND_TASK);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<array{mime: string, base64: string}>  $images
     * @param  array{aspect_ratio: string, resolution: string}  $output
     * @return array<string, mixed>
     */
    private static function request(array $entry, array $images, string $prompt, array $output, string $task): array
    {
        return [
            'model' => (string) $entry['model'],
            'input' => [
                ...array_map(static fn (array $image): array => [
                    'type' => 'image',
                    'data' => $image['base64'],
                    'mime_type' => $image['mime'],
                ], $images),
                ['type' => 'text', 'text' => $prompt],
            ],
            'response_format' => [
                'type' => 'video',
                'aspect_ratio' => $output['aspect_ratio'],
                'resolution' => $output['resolution'],
                'delivery' => 'uri',
            ],
            'generation_config' => [
                'video_config' => ['task' => $task],
            ],
            'background' => false,
            'store' => true,
        ];
    }

    /**
     * @param  array{max_long_edge: int, max_bytes: int, jpeg_quality: int, processor: string}  $policy
     * @return array{ok: bool, error: ?string, mime: ?string, base64: ?string, sent_sha256: ?string, sent_bytes: int,
     *               sent_width: ?int, sent_height: ?int, transform: array<string, mixed>}
     */
    public static function reference(string $bytes, string $mime, array $policy): array
    {
        $size = @getimagesizefromstring($bytes);

        if (! is_array($size) || $size[0] < 1 || $size[1] < 1) {
            return self::referenceFail('khong doc duoc kich thuoc anh');
        }

        if (($size['mime'] ?? null) !== $mime) {
            return self::referenceFail('MIME khai bao '.$mime.' khac dinh dang that '.($size['mime'] ?? 'khong ro'));
        }

        try {
            $source = @imagecreatefromstring($bytes);
        } catch (\Throwable) {
            $source = false;
        }

        if ($source === false) {
            return self::referenceFail('GD khong giai ma duoc anh '.$mime.' — du lieu anh hong');
        }

        [$width, $height] = $size;
        $longEdge = max($width, $height);

        if ($longEdge <= $policy['max_long_edge'] && strlen($bytes) <= $policy['max_bytes']) {
            return self::referenceOk($bytes, $mime, $width, $height, ['type' => 'none', 'processor' => $policy['processor']]);
        }

        $scale = min(1, $policy['max_long_edge'] / $longEdge);
        $toWidth = max(1, (int) round($width * $scale));
        $toHeight = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($toWidth, $toHeight);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $toWidth, $toHeight, $width, $height);
        ob_start();
        imagejpeg($canvas, null, $policy['jpeg_quality']);
        $jpeg = (string) ob_get_clean();

        if ($jpeg === '' || strlen($jpeg) > $policy['max_bytes']) {
            return self::referenceFail(sprintf('ban dan xuat %dx%d nang %d byte, vuot %d byte', $toWidth, $toHeight, strlen($jpeg), $policy['max_bytes']));
        }

        return self::referenceOk($jpeg, 'image/jpeg', $toWidth, $toHeight, [
            'type' => 'resize_jpeg',
            'processor' => $policy['processor'],
            'from' => [$width, $height],
            'to' => [$toWidth, $toHeight],
            'jpeg_quality' => $policy['jpeg_quality'],
            'crop' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, ambiguous: bool, job_id: ?string, request_id: ?string,
     *               response: array<string, mixed>, failure: ?RenderFailureClass, error: ?string}
     */
    public function submit(array $entry, array $payload): array
    {
        if (trim($this->apiKey) === '') {
            return $this->submitFail(RenderFailureClass::AUTHENTICATION, 'GEMINI_API_KEY chua cau hinh.', []);
        }

        try {
            $response = $this->http
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->asJson()
                ->timeout($this->timeoutSeconds)
                ->post(sprintf('%s/%s/%s', rtrim($this->baseUrl, '/'), $entry['api_version'], VideoModelRegistry::INTERACTIONS), $payload);
        } catch (ConnectionException $e) {
            return $this->ambiguous('ket noi dut sau khi da gui: '.Str::limit($e->getMessage(), 200));
        }

        $body = self::scrub((array) $response->json());

        if ($response->failed()) {
            return $this->submitFail($this->classify($response), sprintf(
                'Omni %d: %s',
                $response->status(),
                Str::limit((string) ($response->json('error.message') ?? $response->body()), 300),
            ), $body);
        }

        $uri = $this->videoUriFrom((array) $response->json());
        $file = $uri === null || preg_match(self::FILE_PATTERN, $uri, $match) !== 1 ? null : 'files/'.$match[1];

        if ($file === null) {
            return $this->ambiguous(sprintf(
                'Omni tra 200 nhung khong co uri video hop le; video co the da duoc sinh va tinh phi — interaction %s giu trong response de doi soat, khong gui lai.',
                is_string($body['id'] ?? null) ? $body['id'] : '(khong co id)',
            ), $body);
        }

        return [
            'ok' => true,
            'ambiguous' => false,
            'job_id' => $file,
            'request_id' => $response->header('x-request-id') ?: null,
            'response' => $body,
            'failure' => null,
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{ok: bool, done: bool, video_uri: ?string, response: array<string, mixed>,
     *               failure: ?RenderFailureClass, error: ?string}
     */
    public function poll(array $entry, string $jobId): array
    {
        if (preg_match('#^files/[A-Za-z0-9_-]+$#', $jobId) !== 1) {
            return $this->pollFail(RenderFailureClass::INVALID_REQUEST, 'job id khong phai files/{id}: '.$jobId, [], true);
        }

        $base = sprintf('%s/%s/%s', rtrim($this->baseUrl, '/'), $entry['api_version'], $jobId);

        try {
            $response = $this->http
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->timeout($this->pollTimeoutSeconds)
                ->retry(2, 1000, when: static fn (Throwable $e): bool => $e instanceof RequestException
                    && $e->response->status() === 429, throw: false)
                ->get($base);
        } catch (ConnectionException $e) {
            return $this->pollFail(RenderFailureClass::TRANSIENT_NETWORK,
                'khong noi duoc de poll: '.Str::limit($e->getMessage(), 200), [], false);
        }

        $body = self::scrub((array) $response->json());

        if ($response->failed()) {
            return $this->pollFail($this->classify($response), sprintf(
                'Omni file %d: %s',
                $response->status(),
                Str::limit((string) ($response->json('error.message') ?? $response->body()), 300),
            ), $body, false);
        }

        return match ($body['state'] ?? null) {
            'ACTIVE' => ['ok' => true, 'done' => true, 'video_uri' => $base.':download?alt=media',
                'response' => $body, 'failure' => null, 'error' => null],
            'FAILED' => $this->pollFail(RenderFailureClass::PROVIDER_5XX,
                'file Omni FAILED: '.Str::limit((string) json_encode($body['error'] ?? null), 300), $body, true),
            default => ['ok' => true, 'done' => false, 'video_uri' => null,
                'response' => $body, 'failure' => null, 'error' => null],
        };
    }

    /**
     * @return array{ok: bool, sha256: ?string, bytes: int, mime: ?string, local_path: ?string,
     *               failure: ?RenderFailureClass, error: ?string}
     */
    public function download(string $uri, string $path): array
    {
        return $this->files->download($uri, $path);
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    public static function scrub(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::scrub($item);
            } elseif ($key === 'data' && is_string($item) && strlen($item) > self::SCRUB_FROM_CHARS) {
                $value[$key] = '[base64 omitted: '.strlen($item).' chars]';
            }
        }

        return $value;
    }

    /** @param array<mixed> $body */
    private function videoUriFrom(array $body): ?string
    {
        $found = null;
        $walk = function (mixed $node) use (&$walk, &$found): void {
            if ($found !== null || ! is_array($node)) {
                return;
            }

            if (($node['type'] ?? null) === 'video' && is_string($node['uri'] ?? null)) {
                $found = $node['uri'];

                return;
            }

            foreach ($node as $child) {
                $walk($child);
            }
        };

        foreach (is_array($body['steps'] ?? null) ? $body['steps'] : [] as $step) {
            if (is_array($step) && ($step['type'] ?? null) === self::OUTPUT_STEP) {
                $walk($step);
            }
        }

        return $found !== null && $this->trusted($found) ? $found : null;
    }

    private function trusted(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower((string) ($parts['host'] ?? '')), $this->downloadHosts, true);
    }

    /**
     * @param  array<string, mixed>  $transform
     * @return array{ok: bool, error: ?string, mime: ?string, base64: ?string, sent_sha256: ?string, sent_bytes: int,
     *               sent_width: ?int, sent_height: ?int, transform: array<string, mixed>}
     */
    private static function referenceOk(string $bytes, string $mime, int $width, int $height, array $transform): array
    {
        return ['ok' => true, 'error' => null, 'mime' => $mime, 'base64' => base64_encode($bytes),
            'sent_sha256' => hash('sha256', $bytes), 'sent_bytes' => strlen($bytes),
            'sent_width' => $width, 'sent_height' => $height, 'transform' => $transform];
    }

    /**
     * @return array{ok: bool, error: ?string, mime: ?string, base64: ?string, sent_sha256: ?string, sent_bytes: int,
     *               sent_width: ?int, sent_height: ?int, transform: array<string, mixed>}
     */
    private static function referenceFail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'mime' => null, 'base64' => null, 'sent_sha256' => null, 'sent_bytes' => 0,
            'sent_width' => null, 'sent_height' => null, 'transform' => []];
    }

    private function classify(Response $response): RenderFailureClass
    {
        return match (true) {
            in_array($response->status(), [401, 403], true) => RenderFailureClass::AUTHENTICATION,
            $response->status() === 429 => RenderFailureClass::RATE_LIMIT,
            $response->serverError() => RenderFailureClass::PROVIDER_5XX,
            default => RenderFailureClass::INVALID_REQUEST,
        };
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{ok: bool, ambiguous: bool, job_id: ?string, request_id: ?string, response: array<string, mixed>, failure: ?RenderFailureClass, error: ?string}
     */
    private function submitFail(RenderFailureClass $class, string $message, array $response): array
    {
        return ['ok' => false, 'ambiguous' => false, 'job_id' => null, 'request_id' => null,
            'response' => $response, 'failure' => $class, 'error' => $message];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{ok: bool, ambiguous: bool, job_id: ?string, request_id: ?string, response: array<string, mixed>, failure: ?RenderFailureClass, error: ?string}
     */
    private function ambiguous(string $message, array $response = []): array
    {
        return ['ok' => false, 'ambiguous' => true, 'job_id' => null, 'request_id' => null,
            'response' => $response, 'failure' => RenderFailureClass::AMBIGUOUS_PROVIDER_OUTCOME, 'error' => $message];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{ok: bool, done: bool, video_uri: ?string, response: array<string, mixed>, failure: ?RenderFailureClass, error: ?string}
     */
    private function pollFail(RenderFailureClass $class, string $message, array $response, bool $done): array
    {
        return ['ok' => false, 'done' => $done, 'video_uri' => null, 'response' => $response,
            'failure' => $class, 'error' => $message];
    }
}
