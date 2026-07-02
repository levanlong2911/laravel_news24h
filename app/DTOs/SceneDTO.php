<?php

namespace App\DTOs;

/** A scene with its shots — output of SceneShotPlanner, input to SceneGraphBuilder. */
final class SceneDTO
{
    /** @var ShotDTO[] */
    private readonly array $shots;

    public function __construct(
        public readonly string $sceneId,
        public readonly int    $sceneNumber,
        public readonly string $title,
        public readonly string $emotion,
        public readonly float  $duration,
        array $shots,
    ) {
        $this->shots = $shots;
    }

    public static function fromArray(array $data): self
    {
        $shots = array_map(fn (array $s) => ShotDTO::fromArray($s), $data['shots']);
        return new self(
            sceneId:     $data['scene_id'] ?? '',
            sceneNumber: (int) $data['scene_number'],
            title:       $data['title'],
            emotion:     $data['emotion'],
            duration:    (float) $data['duration'],
            shots:       $shots,
        );
    }

    /** @return ShotDTO[] */
    public function shots(): array
    {
        return $this->shots;
    }

    public function shotCount(): int
    {
        return count($this->shots);
    }

    public function totalShotDuration(): float
    {
        return array_sum(array_map(fn (ShotDTO $s) => $s->dur, $this->shots));
    }

    public function toArray(): array
    {
        return [
            'scene_id'     => $this->sceneId,
            'scene_number' => $this->sceneNumber,
            'title'        => $this->title,
            'emotion'      => $this->emotion,
            'duration'     => $this->duration,
            'shots'        => array_map(fn (ShotDTO $s) => $s->toArray(), $this->shots),
        ];
    }
}
