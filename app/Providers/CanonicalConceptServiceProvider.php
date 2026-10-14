<?php

declare(strict_types=1);

namespace App\Providers;

use App\Video\Concept\Orchestration\CanonicalConceptInputBuilder;
use App\Video\Concept\Support\Clock;
use App\Video\Concept\Support\SystemClock;
use App\Video\Inspiration\ClaudeInspirationAnalyst;
use App\Video\Inspiration\InspirationBuilder;
use App\Video\Llm\ClaudeWriterAdapter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class CanonicalConceptServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            Clock::class,
            SystemClock::class
        );

        $this->app->singleton(
            ClaudeInspirationAnalyst::class,
            function (
                Application $app
            ): ClaudeInspirationAnalyst {
                return new ClaudeInspirationAnalyst(
                    $app->make(
                        ClaudeWriterAdapter::class
                    )
                );
            }
        );

        $this->app->singleton(
            InspirationBuilder::class
        );

        $this->app->singleton(
            CanonicalConceptInputBuilder::class
        );
    }
}
