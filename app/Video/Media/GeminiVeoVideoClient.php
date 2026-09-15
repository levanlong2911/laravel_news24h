<?php

namespace App\Video\Media;

use App\Video\Render\Enums\RenderFailureClass;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Throwable;

/**
 * Noi duy nhat biet endpoint, auth va hinh dang response cua Veo.
 *
 * Hai luat giu tien:
 *   1. Request co the da toi provider ma ta khong cam duoc job id => ambiguous,
 *      KHONG bao gio gui lai. Goi ham phai danh render la provider_unknown.
 *   2. Khong doan truong nao cua response. URI video chi duoc nhan o dung cac
 *      duong dan da khai bao, va phai la https tren host trong allowlist.
 */
final class GeminiVeoVideoClient
{
    private const ALLOWED_MIME = 'video/mp4';

    private const MAX_REDIRECTS = 3;

    /** @var list<string> */
    private const URI_PATHS = [
        'response.generateVideoResponse.generatedSamples.0.video.uri',
        'response.generatedVideos.0.video.uri',
    ];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly FilesystemFactory $storage,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $disk,
        private readonly int $timeoutSeconds,
        private readonly int $maxBytes,
        /** @var list<string> */
        private readonly array $downloadHosts,
    ) {}

    /**
     * @param  array<string, mixed>  $entry  entry registry cua dung model
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, ambiguous: bool, job_id: ?string, request_id: ?string,
     *               response: array<string, mixed>, failure: ?RenderFailureClass, error: ?string}
     */
    public function submit(array $entry, array $payload): array
    {
        if (trim($this->apiKey) === '') {
            return $this->submitFail(RenderFailureClass::AUTHENTICATION, 'GEMINI_API_KEY chua cau hinh.');
        }

        $url = sprintf(
            '%s/%s/models/%s:predictLongRunning',
            rtrim($this->baseUrl, '/'),
            $entry['api_version'],
            $entry['model'],
        );

        try {
            $response = $this->http
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->timeout($this->timeoutSeconds)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            return $this->ambiguous('ket noi dut sau khi da gui: '.Str::limit($e->getMessage(), 200));
        }

        if ($response->failed()) {
            return $this->submitFail($this->classify($response), sprintf(
                'Veo %d: %s',
                $response->status(),
                Str::limit((string) ($response->json('error.message') ?? $response->body()), 300),
            ));
        }

        $name = $response->json('name');

        if (! is_string($name) || $name === '') {
            return $this->ambiguous('provider nhan request nhung khong tra operation name');
        }

        return [
            'ok' => true,
            'ambiguous' => false,
            'job_id' => $name,
            'request_id' => $response->header('x-request-id') ?: null,
            'response' => (array) $response->json(),
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
        $url = sprintf('%s/%s/%s', rtrim($this->baseUrl, '/'), $entry['api_version'], ltrim($jobId, '/'));

        try {
            $response = $this->http
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->timeout($this->timeoutSeconds)
                ->retry(2, 1000, when: static fn (Throwable $e): bool => $e instanceof RequestException
                    && $e->response->status() === 429, throw: false)
                ->get($url);
        } catch (ConnectionException $e) {
            return $this->pollFail(
                RenderFailureClass::TRANSIENT_NETWORK,
                'khong noi duoc de poll: '.Str::limit($e->getMessage(), 200),
                [],
            );
        }

        if ($response->failed()) {
            return $this->pollFail($this->classify($response), sprintf(
                'Veo poll %d: %s',
                $response->status(),
                Str::limit((string) ($response->json('error.message') ?? $response->body()), 300),
            ), (array) $response->json());
        }

        $body = (array) $response->json();

        if (($body['done'] ?? false) !== true) {
            return ['ok' => true, 'done' => false, 'video_uri' => null, 'response' => $body,
                'failure' => null, 'error' => null];
        }

        if (isset($body['error'])) {
            return ['ok' => false, 'done' => true, 'video_uri' => null, 'response' => $body,
                'failure' => RenderFailureClass::INVALID_REQUEST,
                'error' => 'operation loi: '.Str::limit((string) json_encode($body['error']), 300)];
        }

        return ['ok' => true, 'done' => true, 'video_uri' => $this->videoUriFrom($body),
            'response' => $body, 'failure' => null, 'error' => null];
    }

    /**
     * @return array{ok: bool, sha256: ?string, bytes: int, mime: ?string, local_path: ?string,
     *               failure: ?RenderFailureClass, error: ?string}
     */
    public function download(string $uri, string $path): array
    {
        $temp = tempnam(sys_get_temp_dir(), 'veo_');

        if ($temp === false) {
            return $this->downloadFail(RenderFailureClass::INTERNAL, 'khong tao duoc file tam');
        }

        $current = $uri;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $rejected = $this->rejectUrl($current);

            if ($rejected !== null) {
                @unlink($temp);

                return $this->downloadFail(RenderFailureClass::ARTIFACT_INTEGRITY, $rejected);
            }

            try {
                $response = $this->http
                    ->withHeaders(['x-goog-api-key' => $this->apiKey])
                    ->withoutRedirecting()
                    ->withOptions(['stream' => true])
                    ->timeout($this->timeoutSeconds)
                    ->get($current);
            } catch (ConnectionException $e) {
                @unlink($temp);

                return $this->downloadFail(
                    RenderFailureClass::TRANSIENT_NETWORK,
                    'dut khi tai: '.Str::limit($e->getMessage(), 200),
                );
            }

            if ($response->redirect()) {
                $current = (string) $response->header('Location');

                continue;
            }

            $spilled = $this->spill($response, $temp);

            if ($spilled !== null) {
                @unlink($temp);

                return $spilled;
            }

            $verdict = $this->verify($response, $temp);

            if ($verdict !== null) {
                @unlink($temp);

                return $verdict;
            }

            $handle = fopen($temp, 'rb');

            if ($handle === false) {
                @unlink($temp);

                return $this->downloadFail(RenderFailureClass::INTERNAL, 'khong doc duoc file tam');
            }

            $written = $this->storage->disk($this->disk)->writeStream($path, $handle);

            if (is_resource($handle)) {
                fclose($handle);
            }

            if ($written === false) {
                @unlink($temp);

                return $this->downloadFail(RenderFailureClass::INTERNAL, 'khong ghi duoc vao disk '.$this->disk);
            }

            return [
                'ok' => true,
                'sha256' => (string) hash_file('sha256', $temp),
                'bytes' => (int) filesize($temp),
                'mime' => self::ALLOWED_MIME,
                'local_path' => $temp,
                'failure' => null,
                'error' => null,
            ];
        }

        @unlink($temp);

        return $this->downloadFail(RenderFailureClass::ARTIFACT_INTEGRITY, 'qua nhieu redirect');
    }

    /**
     * Doc luong theo tung khuc de tran gioi han dung luc tai, khong phai sau khi
     * ca file da nam trong bo nho.
     *
     * @return array{ok: bool, sha256: ?string, bytes: int, mime: ?string, local_path: ?string,
     *               failure: ?RenderFailureClass, error: ?string}|null
     */
    private function spill(Response $response, string $temp): ?array
    {
        if ($response->failed()) {
            return null;
        }

        $out = fopen($temp, 'wb');

        if ($out === false) {
            return $this->downloadFail(RenderFailureClass::INTERNAL, 'khong mo duoc file tam de ghi');
        }

        $body = $response->toPsrResponse()->getBody();
        $written = 0;

        while (! $body->eof()) {
            $chunk = $body->read(1048576);

            if ($chunk === '') {
                break;
            }

            $written += strlen($chunk);

            if ($written > $this->maxBytes) {
                fclose($out);

                return $this->downloadFail(
                    RenderFailureClass::ARTIFACT_INTEGRITY,
                    'file vuot gioi han '.$this->maxBytes.' byte',
                );
            }

            fwrite($out, $chunk);
        }

        fclose($out);

        return null;
    }

    /**
     * @return array{ok: bool, sha256: ?string, bytes: int, mime: ?string, local_path: ?string,
     *               failure: ?RenderFailureClass, error: ?string}|null
     */
    private function verify(Response $response, string $temp): ?array
    {
        if ($response->failed()) {
            return $this->downloadFail($this->classify($response), 'tai that bai: '.$response->status());
        }

        $mime = trim(Str::before((string) $response->header('Content-Type'), ';'));

        if ($mime !== self::ALLOWED_MIME) {
            return $this->downloadFail(
                RenderFailureClass::ARTIFACT_INTEGRITY,
                'content-type khong phai video/mp4: '.($mime !== '' ? $mime : 'trong'),
            );
        }

        $size = (int) filesize($temp);

        if ($size <= 0 || $size > $this->maxBytes) {
            return $this->downloadFail(RenderFailureClass::ARTIFACT_INTEGRITY, 'kich thuoc khong hop le: '.$size);
        }

        if (substr((string) file_get_contents($temp, false, null, 0, 12), 4, 4) !== 'ftyp') {
            return $this->downloadFail(RenderFailureClass::ARTIFACT_INTEGRITY, 'file khong co hop ftyp');
        }

        return null;
    }

    private function rejectUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return 'chi nhan https, thay: '.($parts['scheme'] ?? '-');
        }

        if (! in_array(strtolower((string) ($parts['host'] ?? '')), $this->downloadHosts, true)) {
            return 'host ngoai allowlist: '.($parts['host'] ?? '-');
        }

        return null;
    }

    /** @param array<string, mixed> $body */
    private function videoUriFrom(array $body): ?string
    {
        foreach (self::URI_PATHS as $path) {
            $value = data_get($body, $path);

            if (is_string($value) && $this->rejectUrl($value) === null) {
                return $value;
            }
        }

        return null;
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

    /** @return array{ok: bool, ambiguous: bool, job_id: ?string, request_id: ?string, response: array<string, mixed>, failure: ?RenderFailureClass, error: ?string} */
    private function submitFail(RenderFailureClass $class, string $message): array
    {
        return ['ok' => false, 'ambiguous' => false, 'job_id' => null, 'request_id' => null,
            'response' => [], 'failure' => $class, 'error' => $message];
    }

    /** @return array{ok: bool, ambiguous: bool, job_id: ?string, request_id: ?string, response: array<string, mixed>, failure: ?RenderFailureClass, error: ?string} */
    private function ambiguous(string $message): array
    {
        return ['ok' => false, 'ambiguous' => true, 'job_id' => null, 'request_id' => null,
            'response' => [], 'failure' => RenderFailureClass::AMBIGUOUS_PROVIDER_OUTCOME, 'error' => $message];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{ok: bool, done: bool, video_uri: ?string, response: array<string, mixed>, failure: ?RenderFailureClass, error: ?string}
     */
    private function pollFail(RenderFailureClass $class, string $message, array $response): array
    {
        return ['ok' => false, 'done' => false, 'video_uri' => null, 'response' => $response,
            'failure' => $class, 'error' => $message];
    }

    /** @return array{ok: bool, sha256: ?string, bytes: int, mime: ?string, local_path: ?string, failure: ?RenderFailureClass, error: ?string} */
    private function downloadFail(RenderFailureClass $class, string $message): array
    {
        return ['ok' => false, 'sha256' => null, 'bytes' => 0, 'mime' => null, 'local_path' => null,
            'failure' => $class, 'error' => $message];
    }
}
