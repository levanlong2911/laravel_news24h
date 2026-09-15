<?php

namespace App\Providers;

use App\Models\PromptFramework;
use App\Observers\PromptFrameworkObserver;
use App\Services\ImageProxyService;
use App\Services\Video\GeminiImageClient;
use App\Services\Video\OpenAiImageClient;
use App\Video\Media\GeminiVeoVideoClient;
use App\Video\Media\MediaModelRegistry;
use App\Video\Media\Mp4Probe;
use App\Video\Prompt\AnthropicTextClient;
use App\Video\Prompt\OpenAiTextClient;
use App\Video\Prompt\GeometryPromptAuthor;
use App\Video\Prompt\TextCompletionClient;
use App\Video\Scene\ScenePlanAuthor;
use App\Video\Scene\ScenePlanReviewer;
use GuzzleHttp\Client;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ImageProxyService::class, fn () => new ImageProxyService(new Client));

        $this->app->singleton(
            OpenAiImageClient::class,
            static fn (Application $app): OpenAiImageClient => new OpenAiImageClient(
                http: $app->make(HttpFactory::class),
                storage: $app->make(FilesystemFactory::class),
                apiKey: (string) config('canonical_concept.openai.api_key'),
                baseUrl: (string) config('canonical_concept.openai.base_url'),
                disk: (string) config('video.openai_image.disk'),
                memoryLimit: (string) config('video.openai_image.memory_limit'),
                timeoutSeconds: (int) config('video.openai_image.timeout'),
            ),
        );

        $this->app->singleton(
            GeminiImageClient::class,
            static fn (Application $app): GeminiImageClient => new GeminiImageClient(
                http: $app->make(HttpFactory::class),
                storage: $app->make(FilesystemFactory::class),
                registry: $app->make(MediaModelRegistry::class),
                apiKey: (string) config('video.gemini.api_key'),
                baseUrl: (string) config('video.gemini.base_url'),
                disk: (string) config('video.gemini.disk'),
                memoryLimit: (string) config('video.openai_image.memory_limit'),
                timeoutSeconds: (int) config('video.gemini.image_timeout'),
            ),
        );

        $this->app->singleton(
            Mp4Probe::class,
            static fn (): Mp4Probe => new Mp4Probe(
                binary: (string) config('video.veo.ffprobe_bin'),
                timeoutSeconds: (int) config('video.veo.ffprobe_timeout'),
            ),
        );

        $this->app->singleton(
            GeminiVeoVideoClient::class,
            static fn (Application $app): GeminiVeoVideoClient => new GeminiVeoVideoClient(
                http: $app->make(HttpFactory::class),
                storage: $app->make(FilesystemFactory::class),
                apiKey: (string) config('video.gemini.api_key'),
                baseUrl: (string) config('video.gemini.base_url'),
                disk: (string) config('video.veo.disk'),
                timeoutSeconds: (int) config('video.veo.timeout'),
                maxBytes: (int) config('video.veo.max_bytes'),
                downloadHosts: (array) config('video.veo.download_hosts'),
            ),
        );

        $this->app->singleton(
            TextCompletionClient::class,
            static function (Application $app): TextCompletionClient {
                $http = $app->make(HttpFactory::class);
                $timeout = (int) config('image_prompt.timeout_seconds');
                $retryTimes = (int) config('image_prompt.retry_times');
                $retrySleep = (int) config('image_prompt.retry_sleep_ms');

                if (config('canonical_concept.provider') === 'openai') {
                    return new OpenAiTextClient(
                        http: $http,
                        apiKey: (string) config('canonical_concept.openai.api_key'),
                        baseUrl: (string) config('canonical_concept.openai.base_url'),
                        reasoningEffort: (string) config('canonical_concept.openai.reasoning_effort'),
                        timeoutSeconds: $timeout,
                        retryTimes: $retryTimes,
                        retrySleepMs: $retrySleep,
                    );
                }

                return new AnthropicTextClient(
                    http: $http,
                    apiKey: (string) config('canonical_concept.anthropic.api_key'),
                    baseUrl: (string) config('canonical_concept.anthropic.base_url'),
                    apiVersion: (string) config('canonical_concept.anthropic.api_version'),
                    timeoutSeconds: $timeout,
                    retryTimes: $retryTimes,
                    retrySleepMs: $retrySleep,
                );
            }
        );

        $this->app->singleton(
            GeometryPromptAuthor::class,
            static function (Application $app): GeometryPromptAuthor {
                return new GeometryPromptAuthor(
                    client: $app->make(TextCompletionClient::class),
                    promptPath: (string) config('image_prompt.prompt_path'),
                    promptVersion: (string) config('image_prompt.prompt_version'),
                    model: (string) config('canonical_concept.'.config('canonical_concept.provider').'.model'),
                    maxTokens: (int) config('image_prompt.'.config('canonical_concept.provider').'.max_tokens'),
                );
            }
        );

        $this->app->singleton(
            ScenePlanAuthor::class,
            static function (Application $app): ScenePlanAuthor {
                return new ScenePlanAuthor(
                    client: $app->make(TextCompletionClient::class),
                    promptPath: (string) config('video.scene_plan.prompt_path'),
                    promptVersion: (string) config('video.scene_plan.prompt_version'),
                    model: (string) (config('video.scene_plan.model')
                        ?: config('canonical_concept.'.config('canonical_concept.provider').'.model')),
                    maxTokens: (int) config('video.scene_plan.max_tokens'),
                    maxScenes: (int) config('video.scene_plan.max_scenes'),
                );
            }
        );

        $this->app->singleton(
            ScenePlanReviewer::class,
            static function (Application $app): ScenePlanReviewer {
                return new ScenePlanReviewer(
                    client: $app->make(TextCompletionClient::class),
                    promptPath: (string) config('video.scene_plan.review.prompt_path'),
                    promptVersion: (string) config('video.scene_plan.review.prompt_version'),
                    model: (string) (config('video.scene_plan.review.model')
                        ?: config('video.scene_plan.model')
                        ?: config('canonical_concept.'.config('canonical_concept.provider').'.model')),
                    maxTokens: (int) config('video.scene_plan.review.max_tokens'),
                );
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        PromptFramework::observe(PromptFrameworkObserver::class);

        if (app()->runningInConsole()) {
            return;
        }
    }
}
