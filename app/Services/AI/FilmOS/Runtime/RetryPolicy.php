<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Runtime;

final class RetryPolicy
{
    public function __construct(
        public readonly int   $maxAttempts,
        public readonly float $backoff,
        public readonly array $retryableErrors = [],
    ) {}

    public static function default(): self
    {
        return new self(
            maxAttempts:    3,
            backoff:        5.0,
            retryableErrors: [
                RuntimeEvent::TIMEOUT->value,
                RuntimeEvent::POLLING->value,
            ],
        );
    }

    public function isRetryable(RuntimeEvent $event): bool
    {
        if (empty($this->retryableErrors)) {
            return true;
        }
        return in_array($event->value, $this->retryableErrors, true);
    }
}
