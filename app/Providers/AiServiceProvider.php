<?php

namespace App\Providers;

use App\Services\AI\Artifact\ArtifactStorage;
use App\Services\AI\Artifact\ArtifactStorageInterface;
use App\Services\AI\Provider\Kling\KlingVideoProvider;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ArtifactStorage resolved fresh from config (disk + timeout are config-driven).
        $this->app->bind(ArtifactStorageInterface::class, fn () => ArtifactStorage::fromConfig());

        // KlingVideoProvider resolved fresh from config each time (stateless HTTP client).
        $this->app->bind(KlingVideoProvider::class, fn () => KlingVideoProvider::fromConfig());
    }
}
