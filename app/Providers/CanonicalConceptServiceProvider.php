<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Video\DesignDnaService;
use App\Services\Video\ScriptWriterService;
use App\Services\Video\YachtDesignService;
use App\Video\Concept\Orchestration\CanonicalConceptInputBuilder;
use App\Video\Concept\Support\Clock;
use App\Video\Concept\Support\SystemClock;
use App\Video\Inspiration\ClaudeInspirationAnalyst;
use App\Video\Llm\ClaudeStreamClient;
use App\Video\Llm\ClaudeWriterAdapter;
use App\Video\Llm\LlmClient;
use App\Video\Prompt\OpenAiTextClient;
use App\Video\Prompt\TextCompletionClient;
use Illuminate\Http\Client\Factory as HttpFactory;
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
            CanonicalConceptInputBuilder::class
        );

        $this->app->when(DesignDnaService::class)
            ->needs(LlmClient::class)
            ->give(ClaudeWriterAdapter::class);

        $this->app->when(ScriptWriterService::class)
            ->needs(LlmClient::class)
            ->give(static fn (Application $app): ClaudeStreamClient => new ClaudeStreamClient(
                http: $app->make(HttpFactory::class),
                apiKey: (string) config('services.claude.key'),
                baseUrl: (string) config('canonical_concept.anthropic.base_url'),
                apiVersion: (string) config('services.claude.version'),
                idleTimeoutSeconds: (int) config('video.superyacht.script.stream.idle_timeout_seconds'),
                progressEverySeconds: (int) config('video.superyacht.script.stream.progress_every_seconds'),
                thinking: (array) config('video.superyacht.script.stream.thinking'),
            ));

        $openAi = static fn (Application $app, array $settings): OpenAiTextClient => new OpenAiTextClient(
            http: $app->make(HttpFactory::class),
            apiKey: (string) config('canonical_concept.openai.api_key'),
            baseUrl: (string) config('canonical_concept.openai.base_url'),
            reasoningEffort: (string) $settings['effort'],
            timeoutSeconds: (int) $settings['timeout_seconds'],
            retryTimes: (int) $settings['retry_times'],
            retrySleepMs: 0,
            stream: (bool) $settings['stream'],
        );

        $this->app->bind('superyacht.judge_client', static fn (Application $app): OpenAiTextClient => $openAi($app, DesignDnaService::judgeSettings()));

        $this->app->bind('superyacht.design_client', static fn (Application $app): OpenAiTextClient => $openAi($app, YachtDesignService::settings()));

        $this->app->when(DesignDnaService::class)
            ->needs(TextCompletionClient::class)
            ->give('superyacht.judge_client');

        $this->app->when(YachtDesignService::class)
            ->needs(TextCompletionClient::class)
            ->give('superyacht.design_client');
    }
}
