<?php

declare(strict_types=1);

namespace App\Video\Llm;

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

    public int $events = 0;

    public int $thinkingChars = 0;

    public string $text = '';

    public string $providerModel = '';

    /** @var array<string, mixed> */
    public array $usage = [];

    public ?float $lastEventAt = null;

    private readonly float $startedAt;

    private ?float $emittedAt = null;

    private ?string $emittedPhase = null;

    /**
     * @param  ?\Closure(array<string, mixed>): (bool|null)  $listener
     */
    public function __construct(
        private readonly ?\Closure $listener,
        private readonly int $everySeconds,
        public readonly int $totalSeconds,
        public readonly int $idleSeconds,
    ) {
        $this->startedAt = microtime(true);
    }

    public function appendText(string $text): void
    {
        $this->text .= $text;
    }

    public function overDeadline(): bool
    {
        return microtime(true) - $this->startedAt >= $this->totalSeconds;
    }

    public function elapsedMs(): int
    {
        return (int) round((microtime(true) - $this->startedAt) * 1000);
    }

    /** @return bool false when the listener asked to stop */
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

        return ($this->listener)($this->state()) !== false;
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
            'events' => $this->events,
            'thinking_chars' => $this->thinkingChars,
            'text_chars' => mb_strlen($this->text),
            'input_tokens' => isset($this->usage['input_tokens']) ? (int) $this->usage['input_tokens'] : null,
            'output_tokens' => isset($this->usage['output_tokens']) ? (int) $this->usage['output_tokens'] : null,
            'started_at' => date(DATE_ATOM, (int) $this->startedAt),
            'last_event_at' => $this->lastEventAt === null ? null : date(DATE_ATOM, (int) $this->lastEventAt),
            'elapsed_seconds' => (int) floor(microtime(true) - $this->startedAt),
            'idle_timeout_seconds' => $this->idleSeconds,
            'timeout_seconds' => $this->totalSeconds,
        ];
    }

    public function describe(): string
    {
        $phases = [self::PHASE_WAITING => 'chờ dữ liệu đầu', self::PHASE_THINKING => 'suy nghĩ', self::PHASE_WRITING => 'viết JSON'];

        return sprintf(
            '(sau %d s, đang ở pha %s, đã nhận %d sự kiện: %s ký tự suy nghĩ, %s ký tự JSON) — Anthropic có thể vẫn tính phí phần đã sinh',
            (int) floor(microtime(true) - $this->startedAt),
            $phases[$this->phase] ?? $this->phase,
            $this->events,
            number_format($this->thinkingChars, 0, ',', '.'),
            number_format(mb_strlen($this->text), 0, ',', '.'),
        );
    }
}
