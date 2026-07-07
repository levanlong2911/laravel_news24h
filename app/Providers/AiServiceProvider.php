<?php

namespace App\Providers;

use App\Services\AI\Artifact\ArtifactStorage;
use App\Services\AI\Artifact\ArtifactStorageInterface;
use App\Services\AI\Provider\Circuit\CircuitBreaker;
use App\Services\AI\Provider\Circuit\CircuitBreakerAwareProvider;
use App\Services\AI\Provider\Kling\KlingVideoProvider;
use App\Services\AI\Provider\ProviderRegistry;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ArtifactStorage resolved fresh from config (disk + timeout are config-driven).
        $this->app->bind(ArtifactStorageInterface::class, fn () => ArtifactStorage::fromConfig());

        // KlingVideoProvider resolved fresh from config each time (stateless HTTP client).
        $this->app->bind(KlingVideoProvider::class, fn () => KlingVideoProvider::fromConfig());

        // ProviderRegistry is a singleton — factories registered once, providers created on demand.
        $this->app->singleton(ProviderRegistry::class, function ($app) {
            $registry = new ProviderRegistry();

            $registry->register('kling', function () use ($app) {
                $inner   = $app->make(KlingVideoProvider::class);
                $circuit = new CircuitBreaker(
                    'kling',
                    $app->make(CacheRepository::class),
                    cacheTtl: (int) config('ai.circuit.ttl', 86400),
                );
                return new CircuitBreakerAwareProvider($inner, $circuit);
            });

            return $registry;
        });
    }
}
