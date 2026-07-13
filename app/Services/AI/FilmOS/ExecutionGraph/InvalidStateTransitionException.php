<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\ExecutionGraph;

/**
 * Thrown when ExecutionRuntimeState::transitionTo() receives a status
 * that is not reachable from the current state in the lifecycle machine.
 *
 * Using a named exception (not \LogicException) so callers can catch
 * transition errors specifically without catching unrelated logic errors.
 */
final class InvalidStateTransitionException extends \LogicException
{
    public function __construct(
        ExecutionNodeStatus $from,
        ExecutionNodeStatus $to,
        array $validTargets,
    ) {
        $validNames = implode(', ', array_map(fn($s) => $s->value, $validTargets))
            ?: '(none — terminal state)';

        parent::__construct(
            "Illegal ExecutionRuntimeState transition: {$from->value} → {$to->value}. " .
            "Valid from {$from->value}: {$validNames}."
        );
    }
}
