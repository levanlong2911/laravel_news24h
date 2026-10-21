<?php

namespace App\Video\Superyacht\Support;

use InvalidArgumentException;

/**
 * Ordering helpers for build states BS01-BS12.
 */
final class BuildStates
{
    /** @param array<int,string> $order */
    public function __construct(private readonly array $order)
    {
    }

    public static function fromConfig(array $config): self
    {
        return new self($config['states']);
    }

    public function index(string $state): int
    {
        $i = array_search($state, $this->order, true);
        if ($i === false) {
            throw new InvalidArgumentException("Unknown build state: {$state}");
        }

        return $i;
    }

    public function compare(string $a, string $b): int
    {
        return $this->index($a) <=> $this->index($b);
    }

    public function isDesignState(string $state): bool
    {
        return $this->index($state) < $this->index('BS05');
    }

    /** @return array<int,string> states from $from to $until inclusive (null = to the end) */
    public function range(string $from, ?string $until): array
    {
        $start = $this->index($from);
        $end = $until === null ? count($this->order) - 1 : $this->index($until);

        return array_slice($this->order, $start, max(0, $end - $start + 1));
    }

    /** @return array<int,string> */
    public function all(): array
    {
        return $this->order;
    }
}
