<?php

declare(strict_types=1);

namespace App\Video\Screenplay;

final class ScreenplayResult
{
    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $usage  Khoá đã khớp PlanningStageStore
     */
    public function __construct(
        public readonly array $screenplay,
        public readonly string $rawResponse,
        public readonly string $authorModel,
        public readonly array $usage = [],
    ) {}

    /** @return array<string, mixed> */
    public function toStorage(): array
    {
        return $this->screenplay + ['author_model' => $this->authorModel];
    }
}
