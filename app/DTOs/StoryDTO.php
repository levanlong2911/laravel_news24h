<?php

namespace App\DTOs;

final class StoryDTO
{
    /** @var BeatDTO[] */
    private readonly array $beats;

    public readonly float $totalDuration;

    public function __construct(array $beats)
    {
        $this->beats         = $beats;
        $this->totalDuration = array_sum(array_map(fn (BeatDTO $b) => $b->duration, $beats));
    }

    public static function fromArray(array $data): self
    {
        $beats = array_values(array_map(
            fn (array $b, int $i) => BeatDTO::fromArray($b, $i + 1),
            $data['beats'],
            array_keys($data['beats']),
        ));
        return new self($beats);
    }

    /** @return BeatDTO[] */
    public function beats(): array
    {
        return $this->beats;
    }

    public function beatCount(): int
    {
        return count($this->beats);
    }

    public function toArray(): array
    {
        return [
            'beats'          => array_map(fn (BeatDTO $b) => $b->toArray(), $this->beats),
            'total_duration' => $this->totalDuration,
        ];
    }
}
