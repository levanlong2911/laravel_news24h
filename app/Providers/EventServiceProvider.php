<?php

namespace App\Providers;

use App\Events\AI\ArtifactStored;
use App\Events\AI\RenderCompleted;
use App\Events\AI\RenderFailed;
use App\Events\AI\VideoSubmitted;
use App\Listeners\AI\LogRenderEvent;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],

        // AI render pipeline lifecycle events.
        VideoSubmitted::class  => [LogRenderEvent::class],
        ArtifactStored::class  => [LogRenderEvent::class],
        RenderCompleted::class => [LogRenderEvent::class],
        RenderFailed::class    => [LogRenderEvent::class],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
