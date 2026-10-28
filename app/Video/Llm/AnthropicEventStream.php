<?php

declare(strict_types=1);

namespace App\Video\Llm;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class AnthropicEventStream
{
    /** @var resource|null */
    private $resource;

    private string $buffer = '';

    public function __construct(StreamInterface $body, private readonly float $deadline, private readonly ?int $idleSeconds)
    {
        $resource = $body->detach();

        if (! is_resource($resource)) {
            throw new RuntimeException('The response body is not a readable stream.');
        }

        $this->resource = $resource;
    }

    /**
     * @return \Generator<int, array<string, mixed>> decoded SSE data payloads, in order; each read waits at most min(idle, time left)
     *
     * @throws AnthropicStreamTimeout
     */
    public function events(): \Generator
    {
        $socket = self::isSocket($this->resource);

        while (true) {
            $wait = AnthropicTransport::wait($this->deadline, $this->idleSeconds);

            if ($wait <= 0.0) {
                throw new AnthropicStreamTimeout(AnthropicStreamTimeout::TOTAL, 0.0);
            }

            if ($socket && ! stream_set_timeout($this->resource, (int) floor($wait), (int) (($wait - floor($wait)) * 1_000_000))) {
                throw new RuntimeException('The stream does not accept a read timeout, so its deadline cannot be enforced.');
            }

            $chunk = fread($this->resource, 8192);

            if ($chunk === false || $chunk === '') {
                if ((stream_get_meta_data($this->resource)['timed_out'] ?? false) === true) {
                    throw new AnthropicStreamTimeout(
                        $this->idleSeconds !== null && $wait >= $this->idleSeconds ? AnthropicStreamTimeout::IDLE : AnthropicStreamTimeout::TOTAL,
                        $wait,
                    );
                }

                if (feof($this->resource)) {
                    break;
                }

                usleep(20_000);

                continue;
            }

            $this->buffer = str_replace("\r\n", "\n", $this->buffer.$chunk);

            while (($end = strpos($this->buffer, "\n\n")) !== false) {
                $payload = self::decode(substr($this->buffer, 0, $end));
                $this->buffer = substr($this->buffer, $end + 2);

                if ($payload !== null) {
                    yield $payload;
                }
            }
        }

        $payload = self::decode($this->buffer);
        $this->buffer = '';

        if ($payload !== null) {
            yield $payload;
        }
    }

    public function close(): void
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }

        $this->resource = null;
    }

    public function isClosed(): bool
    {
        return ! is_resource($this->resource);
    }

    /** @return ?array<string, mixed> */
    private static function decode(string $event): ?array
    {
        $data = '';

        foreach (explode("\n", $event) as $line) {
            if (str_starts_with($line, 'data:')) {
                $data .= ltrim(substr($line, 5));
            }
        }

        $payload = $data === '' ? null : json_decode($data, true);

        return is_array($payload) ? $payload : null;
    }

    /** @param resource $resource */
    private static function isSocket($resource): bool
    {
        $meta = stream_get_meta_data($resource);

        return ! in_array(strtoupper((string) ($meta['wrapper_type'] ?? '')), ['PHP'], true)
            && ! in_array(strtoupper((string) ($meta['stream_type'] ?? '')), ['MEMORY', 'TEMP', 'STDIO'], true);
    }
}
