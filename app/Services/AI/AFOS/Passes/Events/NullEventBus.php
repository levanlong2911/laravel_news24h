<?php

namespace App\Services\AI\AFOS\Passes\Events;

/** Default no-op bus — zero overhead, satisfies interface without side-effects. */
final class NullEventBus implements PipelineEventBus
{
    public function dispatch(PipelineEvent $event): void {}
}
