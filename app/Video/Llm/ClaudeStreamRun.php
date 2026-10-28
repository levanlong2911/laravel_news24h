<?php

declare(strict_types=1);

namespace App\Video\Llm;

use Illuminate\Support\Facades\Log;

final class ClaudeStreamRun
{
    public const PHASE_WAITING = 'waiting';

    public const PHASE_THINKING = 'thinking';

    public const PHASE_WRITING = 'writing';

    public string $phase = self::PHASE_WAITING;

    public ?string $ended = null;

    public ?string $stopReason = null;

    public ?string $requestId = null;

    public ?int $httpStatus = null;

    public ?string $error = null;

    public int $attempts = 0;

    public int $events = 0;

    public int $thinkingChars = 0;

    public string $text = '';

    public string $providerModel = '';

    /** @var array<string, mixed> */
    public array $usage = [];

    public bool $usageComplete = false;

    public ?float $lastEventAt = null;

    private readonly float $startedAt;

    private ?float $emittedAt = null;

    private ?string $emittedPhase = null;

    /**
     * @param  ?\Closure(array<string, mixed>): (bool|null)  $listener
     * @param  ?array<string, mixed>  $thinking  the thinking configuration the request was sent with
     */
    public function __construct(
        private readonly ?\Closure $listener,
        private readonly int $everySeconds,
        public readonly int $totalSeconds,
        public readonly ?int $idleSeconds,
        private readonly ?array $thinking = null,
    ) {
        $this->startedAt = microtime(true);
    }

    /**
     * @param  array<string, mixed>  $payload  one decoded SSE event
     * @return ?array<string, mixed> the provider error when the event is one
     */
    public function apply(array $payload): ?array
    {
        $this->events++;
        $this->lastEventAt = microtime(true);

        switch ($payload['type'] ?? null) {
            case 'message_start':
                $message = is_array($payload['message'] ?? null) ? $payload['message'] : [];
                $this->providerModel = (string) ($message['model'] ?? '');
                $this->usage = is_array($message['usage'] ?? null) ? $message['usage'] : [];

                return null;

            case 'content_block_start':
                $type = $payload['content_block']['type'] ?? null;
                $this->phase = $type === 'text' ? self::PHASE_WRITING : ($type === 'thinking' ? self::PHASE_THINKING : $this->phase);

                return null;

            case 'content_block_delta':
                $delta = is_array($payload['delta'] ?? null) ? $payload['delta'] : [];

                if (($delta['type'] ?? null) === 'text_delta') {
                    $this->text .= (string) ($delta['text'] ?? '');
                } elseif (($delta['type'] ?? null) === 'thinking_delta') {
                    $this->thinkingChars += mb_strlen((string) ($delta['thinking'] ?? ''));
                }

                return null;

            case 'message_delta':
                if (array_key_exists('stop_reason', (array) ($payload['delta'] ?? []))) {
                    $this->stopReason = is_string($payload['delta']['stop_reason']) ? $payload['delta']['stop_reason'] : null;
                }

                if (is_array($payload['usage'] ?? null)) {
                    $this->usage = array_replace($this->usage, $payload['usage']);
                    $this->usageComplete = $this->usageComplete || isset($payload['usage']['output_tokens']);
                }

                return null;

            case 'message_stop':
                $this->ended = ClaudeStreamClient::ENDED_MESSAGE_STOP;

                return null;

            case 'error':
                return is_array($payload['error'] ?? null) ? $payload['error'] : ['type' => 'unknown', 'message' => ''];

            default:
                return null;
        }
    }

    public function elapsedMs(): int
    {
        return (int) round((microtime(true) - $this->startedAt) * 1000);
    }

    /** @return bool false when the listener asked to stop; a listener that throws is logged and the call goes on */
    public function emit(bool $force = false): bool
    {
        if ($this->listener === null) {
            return true;
        }

        $now = microtime(true);

        if (! $force && $this->emittedPhase === $this->phase && $this->emittedAt !== null && $now - $this->emittedAt < $this->everySeconds) {
            return true;
        }

        $this->emittedAt = $now;
        $this->emittedPhase = $this->phase;

        try {
            return ($this->listener)($this->state()) !== false;
        } catch (\Throwable $e) {
            try {
                Log::warning('claude stream: the progress listener failed, the call goes on', ['exception' => $e]);
            } catch (\Throwable) {
            }

            return true;
        }
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return [
            'state' => $this->ended === null ? 'streaming' : ($this->error === null ? 'finished' : 'failed'),
            'phase' => $this->phase,
            'ended' => $this->ended,
            'stop_reason' => $this->stopReason,
            'error' => $this->error,
            'request_id' => $this->requestId,
            'http_status' => $this->httpStatus,
            'attempts' => $this->attempts,
            'events' => $this->events,
            'thinking_chars' => $this->thinkingChars,
            'text_chars' => mb_strlen($this->text),
            'input_tokens' => isset($this->usage['input_tokens']) ? (int) $this->usage['input_tokens'] : null,
            'output_tokens' => isset($this->usage['output_tokens']) ? (int) $this->usage['output_tokens'] : null,
            'usage_complete' => $this->usageComplete,
            'started_at' => date(DATE_ATOM, (int) $this->startedAt),
            'last_event_at' => $this->lastEventAt === null ? null : date(DATE_ATOM, (int) $this->lastEventAt),
            'elapsed_seconds' => (int) floor(microtime(true) - $this->startedAt),
            'idle_timeout_seconds' => $this->idleSeconds,
            'timeout_seconds' => $this->totalSeconds,
            'thinking' => $this->thinking,
        ];
    }

    public function describe(): string
    {
        $phases = [self::PHASE_WAITING => 'chờ dữ liệu đầu', self::PHASE_THINKING => 'suy nghĩ', self::PHASE_WRITING => 'viết nội dung'];

        return sprintf(
            '(sau %d s, đang ở pha %s, đã nhận %d sự kiện: %s ký tự suy nghĩ, %s ký tự nội dung)',
            (int) floor(microtime(true) - $this->startedAt),
            $phases[$this->phase] ?? $this->phase,
            $this->events,
            number_format($this->thinkingChars, 0, ',', '.'),
            number_format(mb_strlen($this->text), 0, ',', '.'),
        );
    }
}
