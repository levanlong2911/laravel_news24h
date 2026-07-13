<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

final class PlanningEngine
{
    public function __construct(
        private readonly PlannerRegistry   $registry,
        private readonly PlanningAssembler $assembler,
    ) {}

    /** @param PlanningJob[] $jobs @return PlanningIR[] */
    public function plan(array $jobs): array
    {
        $results = [];
        $order   = 0;

        foreach ($jobs as $job) {
            $order++;
            $contributions = [];

            foreach ($this->registry->plugins() as $plugin) {
                if ($plugin->supports($job->context())) {
                    $contributions[] = $plugin->plan($job);
                }
            }

            $results[] = $this->assembler->assemble($job->context(), $contributions, $order);
        }

        return $results;
    }
}
