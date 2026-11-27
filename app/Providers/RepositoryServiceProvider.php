<?php

namespace App\Providers;

use App\Repositories\Eloquent\AdminRepository;
use App\Repositories\Eloquent\AdsRepository;
use App\Repositories\Eloquent\BaseRepository;
use App\Repositories\Eloquent\CategoryRepository;
use App\Repositories\Eloquent\FontRepository;
use App\Repositories\Eloquent\InforDomainRepository;
use App\Repositories\Eloquent\KeywordRepository;
use App\Repositories\Eloquent\NewsWebRepository;
use App\Repositories\Eloquent\PostRepository;
use App\Repositories\Eloquent\PostTagRepository;
use App\Repositories\Eloquent\PromptFrameworkRepository;
use App\Repositories\Eloquent\RoleRepository;
use App\Repositories\Eloquent\TagRepository;
use App\Repositories\Eloquent\WebsiteRepository;
use App\Repositories\Interfaces\AdminRepositoryInterface;
use App\Repositories\Interfaces\AdsRepositoryInterface;
use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Repositories\Interfaces\FontRepositoryInterface;
use App\Repositories\Interfaces\InforDomainRepositoryInterface;
use App\Repositories\Interfaces\KeywordRepositoryInterface;
use App\Repositories\Interfaces\NewsWebRepositoryInterface;
use App\Repositories\Interfaces\PostRepositoryInterface;
use App\Repositories\Interfaces\PostTagRepositoryInterface;
use App\Repositories\Interfaces\PromptFrameworkRepositoryInterface;
use App\Repositories\Interfaces\RepositoryInterface;
use App\Repositories\Interfaces\RoleRepositoryInterface;
use App\Repositories\Interfaces\TagRepositoryInterface;
use App\Repositories\Interfaces\WebsiteRepositoryInterface;
use App\Repositories\Eloquent\ArticleRepository;
use App\Repositories\Eloquent\VideoProjectRepository;
use App\Repositories\Interfaces\ArticleRepositoryInterface;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Repositories\Eloquent\AiJsonSchemaRepository;
use App\Repositories\Eloquent\AiPhaseRepository;
use App\Repositories\Eloquent\VideoArtifactRepository;
use App\Repositories\Eloquent\VideoCostEntryRepository;
use App\Repositories\Eloquent\VideoDesignImageRepository;
use App\Repositories\Eloquent\VideoPlanningStageRepository;
use App\Repositories\Eloquent\VideoProviderSubmissionReceiptRepository;
use App\Repositories\Eloquent\VideoRenderAttemptRepository;
use App\Repositories\Eloquent\VideoRenderRepository;
use App\Repositories\Eloquent\VideoRenderSceneRepository;
use App\Repositories\Eloquent\VideoReviewDecisionRepository;
use App\Repositories\Eloquent\VideoSessionEventRepository;
use App\Repositories\Eloquent\VideoSessionRepository;
use App\Repositories\Eloquent\VideoShotRepository;
use App\Repositories\Interfaces\AiJsonSchemaRepositoryInterface;
use App\Repositories\Interfaces\AiPhaseRepositoryInterface;
use App\Repositories\Interfaces\VideoArtifactRepositoryInterface;
use App\Repositories\Interfaces\VideoCostEntryRepositoryInterface;
use App\Repositories\Interfaces\VideoDesignImageRepositoryInterface;
use App\Repositories\Interfaces\VideoPlanningStageRepositoryInterface;
use App\Repositories\Interfaces\VideoProviderSubmissionReceiptRepositoryInterface;
use App\Repositories\Interfaces\VideoRenderAttemptRepositoryInterface;
use App\Repositories\Interfaces\VideoRenderRepositoryInterface;
use App\Repositories\Interfaces\VideoRenderSceneRepositoryInterface;
use App\Repositories\Interfaces\VideoReviewDecisionRepositoryInterface;
use App\Repositories\Interfaces\VideoSessionEventRepositoryInterface;
use App\Repositories\Interfaces\VideoSessionRepositoryInterface;
use App\Repositories\Interfaces\VideoShotRepositoryInterface;
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
        $this->app->bind(
            InforDomainRepositoryInterface::class,
            InforDomainRepository::class
        );
        $this->app->bind(
            PostRepositoryInterface::class,
            PostRepository::class
        );
        $this->app->bind(
            PostTagRepositoryInterface::class,
            PostTagRepository::class
        );
        $this->app->bind(
            AdsRepositoryInterface::class,
            AdsRepository::class
        );
        $this->app->bind(
            FontRepositoryInterface::class,
            FontRepository::class
        );
        $this->app->bind(
            WebsiteRepositoryInterface::class,
            WebsiteRepository::class
        );
        $this->app->bind(
            KeywordRepositoryInterface::class,
            KeywordRepository::class
        );
        $this->app->bind(
            NewsWebRepositoryInterface::class,
            NewsWebRepository::class
        );
        $this->app->bind(
            ArticleRepositoryInterface::class,
            ArticleRepository::class
        );
        $this->app->bind(
            VideoProjectRepositoryInterface::class,
            VideoProjectRepository::class
        );
        $this->app->bind(
            PromptFrameworkRepositoryInterface::class,
            PromptFrameworkRepository::class
        );
        $this->app->bind(
            VideoPlanningStageRepositoryInterface::class,
            VideoPlanningStageRepository::class
        );
        $this->app->bind(
            VideoDesignImageRepositoryInterface::class,
            VideoDesignImageRepository::class
        );
        $this->app->bind(
            VideoArtifactRepositoryInterface::class,
            VideoArtifactRepository::class
        );
        $this->app->bind(
            VideoRenderSceneRepositoryInterface::class,
            VideoRenderSceneRepository::class
        );
        $this->app->bind(
            VideoSessionRepositoryInterface::class,
            VideoSessionRepository::class
        );
        $this->app->bind(
            VideoShotRepositoryInterface::class,
            VideoShotRepository::class
        );
        $this->app->bind(
            VideoRenderRepositoryInterface::class,
            VideoRenderRepository::class
        );
        $this->app->bind(
            VideoRenderAttemptRepositoryInterface::class,
            VideoRenderAttemptRepository::class
        );
        $this->app->bind(
            VideoSessionEventRepositoryInterface::class,
            VideoSessionEventRepository::class
        );
        $this->app->bind(
            VideoReviewDecisionRepositoryInterface::class,
            VideoReviewDecisionRepository::class
        );
        $this->app->bind(
            VideoProviderSubmissionReceiptRepositoryInterface::class,
            VideoProviderSubmissionReceiptRepository::class
        );
        $this->app->bind(
            AiPhaseRepositoryInterface::class,
            AiPhaseRepository::class
        );
        $this->app->bind(
            AiJsonSchemaRepositoryInterface::class,
            AiJsonSchemaRepository::class
        );
        $this->app->bind(
            VideoCostEntryRepositoryInterface::class,
            VideoCostEntryRepository::class
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
