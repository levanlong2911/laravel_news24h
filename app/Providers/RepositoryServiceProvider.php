<?php

namespace App\Providers;

use App\Repositories\Eloquent\AdminRepository;
use App\Repositories\Eloquent\BaseRepository;
use App\Repositories\Eloquent\CategoryRepository;
use App\Repositories\Eloquent\RoleRepository;
use App\Repositories\Eloquent\TagRepository;
use App\Repositories\Interfaces\AdminRepositoryInterface;
use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Repositories\Interfaces\RepositoryInterface;
use App\Repositories\Interfaces\RoleRepositoryInterface;
use App\Repositories\Interfaces\TagRepositoryInterface;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Bind Interface với Eloquent Repository
        $this->app->bind(
            RepositoryInterface::class,
            BaseRepository::class
        );
        $this->app->bind(
            AdminRepositoryInterface::class,
            AdminRepository::class
        );
        $this->app->bind(
            RoleRepositoryInterface::class,
            RoleRepository::class
        );
        $this->app->bind(
            CategoryRepositoryInterface::class,
            CategoryRepository::class
        );
        $this->app->bind(
            TagRepositoryInterface::class,
            TagRepository::class
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
