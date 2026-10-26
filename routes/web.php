<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdvertisementController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ClaudeUsageController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\FontController;
use App\Http\Controllers\GetLinkController;
use App\Http\Controllers\GetTagController;
use App\Http\Controllers\InforDomainsController;
use App\Http\Controllers\KeywordController;
use App\Http\Controllers\ModalConfirmController;
use App\Http\Controllers\NewsSourceController;
use App\Http\Controllers\NewsWebController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PromptFrameworkController;
use App\Http\Controllers\RawArticleController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\VideoArtifactController;
use App\Http\Controllers\VideoProjectsController;
use Illuminate\Support\Facades\Route;

// Route::get('/', function () {
//     return view('welcome');
// });

Route::group(['prefix' => '/'], function () {
    // Login
    Route::match(['get', 'post'], '/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login');
    // Logout
    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware('auth')
        ->name('logout');
});

Route::group(
    [
        'prefix' => 'admin',
        'middleware' => 'auth',
    ],
    function () {
        // Image proxy — bypass CORS khi copy ảnh từ Google Images
        Route::get('/image-proxy', [ArticleController::class, 'imageProxy'])->name('image.proxy');

        // Admin — chỉ admin mới truy cập được
        Route::group(['middleware' => 'admin'], function () {
            Route::get('/', [AdminController::class, 'index'])->name('admin.index');
            Route::get('/claude-usage', [ClaudeUsageController::class, 'index'])->name('admin.claude-usage');
            Route::match(['get', 'post'], '/add', [AdminController::class, 'add'])->name('admin.add');
            Route::match(['get', 'post'], '/update/{id}', [AdminController::class, 'update'])->name('admin.update');
            Route::delete('/delete/{id}', [AdminController::class, 'delete'])->name('admin.delete');
            Route::match(['get', 'post'], '/detail/{id}', [AdminController::class, 'detail'])->name('admin.detail');
        });
        // category
        Route::group(['prefix' => 'category'], function () {
            Route::get('/', [CategoryController::class, 'index'])->name('admin.category.index');
            Route::match(['get', 'post'], '/add', [CategoryController::class, 'add'])->name('admin.category.add');
            Route::match(['get', 'post'], '/update/{id}', [CategoryController::class, 'update'])->name('admin.category.update');
            Route::delete('/delete', [CategoryController::class, 'delete'])->name('admin.category.delete');
            Route::match(['get', 'post'], '/detail/{id}', [CategoryController::class, 'detail'])->name('admin.category.detail');
        });
        // tag
        Route::group(['prefix' => 'tag'], function () {
            Route::get('/', [TagController::class, 'index'])->name('tag.index');
            Route::match(['get', 'post'], '/add', [TagController::class, 'add'])->name('tag.add');
            Route::match(['get', 'post'], '/update/{id}', [TagController::class, 'update'])->name('tag.update');
            Route::delete('/delete', [TagController::class, 'delete'])->name('tag.delete');
            Route::match(['get', 'post'], '/detail/{id}', [TagController::class, 'detail'])->name('tag.detail');
        });
        // news web
        Route::group(['prefix' => 'news-web'], function () {
            Route::get('/', [NewsWebController::class, 'index'])->name('news-web.index');
            Route::match(['get', 'post'], '/add', [NewsWebController::class, 'add'])->name('news-web.add');
            Route::match(['get', 'post'], '/update/{id}', [NewsWebController::class, 'update'])->name('news-web.update');
            Route::delete('/delete', [NewsWebController::class, 'delete'])->name('news-web.delete');
            Route::match(['get', 'post'], '/detail/{id}', [NewsWebController::class, 'detail'])->name('news-web.detail');
        });
        // post
        Route::group(['prefix' => 'post'], function () {
            Route::get('/', [PostController::class, 'index'])->name('post.index');
            Route::match(['get', 'post'], '/add', [PostController::class, 'add'])->name('post.add');
            Route::match(['get', 'post'], '/addpost', [PostController::class, 'addPost'])->name('post.addpost');
            Route::match(['get', 'post'], '/update/{id}', [PostController::class, 'update'])->name('post.update');
            Route::delete('/delete', [PostController::class, 'delete'])->name('post.delete');
            Route::match(['get', 'post'], '/post/{slug}', [PostController::class, 'detail'])->name('post.detail');
            // Route::match(["get", "post"], "/detail/{slug}", [PostController::class, "detail"])->name("post.detail");
        });

        // infor domain
        Route::group(['prefix' => 'domain'], function () {
            Route::get('/', [InforDomainsController::class, 'index'])->name('domain.index');
            Route::match(['get', 'post'], '/add', [InforDomainsController::class, 'add'])->name('domain.add');
            Route::match(['get', 'post'], '/update/{id}', [InforDomainsController::class, 'update'])->name('domain.update');
            Route::delete('/delete', [InforDomainsController::class, 'delete'])->name('domain.delete');
            Route::match(['get', 'post'], '/detail/{id}', [InforDomainsController::class, 'detail'])->name('domain.detail');
        });

        // ads
        Route::group(['prefix' => 'ads'], function () {
            Route::get('/', [AdvertisementController::class, 'index'])->name('ads.index');
            Route::match(['get', 'post'], '/add', [AdvertisementController::class, 'add'])->name('ads.add');
            Route::match(['get', 'post'], '/update/{id}', [AdvertisementController::class, 'update'])->name('ads.update');
            Route::delete('/delete', [AdvertisementController::class, 'delete'])->name('ads.delete');
            Route::match(['get', 'post'], '/detail/{id}', [AdvertisementController::class, 'detail'])->name('ads.detail');
        });

        // convert font
        Route::group(['prefix' => 'font'], function () {
            Route::get('/', [FontController::class, 'index'])->name('font.index');
            Route::match(['get', 'post'], '/add', [FontController::class, 'add'])->name('font.add');
            Route::match(['get', 'post'], '/update/{id}', [FontController::class, 'update'])->name('font.update');
            Route::delete('/delete', [FontController::class, 'delete'])->name('font.delete');
            Route::match(['get', 'post'], '/detail/{id}', [FontController::class, 'detail'])->name('font.detail');
        });

        // Domains
        Route::group(['prefix' => 'website'], function () {
            Route::get('/', [DomainController::class, 'index'])->name('website.index');
            Route::match(['get', 'post'], '/add', [DomainController::class, 'add'])->name('website.add');
            Route::match(['get', 'post'], '/update/{id}', [DomainController::class, 'update'])->name('website.update');
            Route::delete('/delete', [DomainController::class, 'delete'])->name('website.delete');
            Route::match(['get', 'post'], '/detail/{id}', [DomainController::class, 'detail'])->name('website.detail');
            Route::post('{domain}/api-key', [DomainController::class, 'generateApiKey']);
        });

        // prompt frameworks — chỉ admin mới truy cập được
        Route::group(['prefix' => 'prompt-framework', 'middleware' => 'admin'], function () {
            Route::get('/', [PromptFrameworkController::class, 'index'])->name('prompt-framework.index');
            Route::match(['get', 'post'], '/add', [PromptFrameworkController::class, 'add'])->name('prompt-framework.add');
            Route::match(['get', 'post'], '/detail/{id}', [PromptFrameworkController::class, 'detail'])->name('prompt-framework.detail');
            Route::match(['get', 'post'], '/edit/{id}', [PromptFrameworkController::class, 'update'])->name('prompt-framework.update');
            Route::delete('/delete', [PromptFrameworkController::class, 'delete'])->name('prompt-framework.delete');
        });

        // video projects — chủ thể sống qua nhiều lượt: ảnh neo & ảnh tham chiếu
        Route::group(['prefix' => 'video-projects'], function () {
            Route::post('/from-article/{article}', [VideoProjectsController::class, 'store'])->name('video-projects.store');
            Route::get('/', [VideoProjectsController::class, 'index'])->name('video-projects.index');
            Route::get('/{id}/anchor', [VideoProjectsController::class, 'anchor'])->name('video-projects.anchor');
            Route::post('/{id}/inspiration', [VideoProjectsController::class, 'inspiration'])->name('video-projects.inspiration');
            Route::post('/{id}/inspiration/reset', [VideoProjectsController::class, 'resetInspiration'])->name('video-projects.inspiration-reset');
            Route::post('/{id}/design-dna', [VideoProjectsController::class, 'designDna'])->name('video-projects.design-dna');
            Route::post('/{id}/design-dna/reset', [VideoProjectsController::class, 'resetDesignDna'])->name('video-projects.design-dna-reset');
            Route::post('/{id}/design-dna/judge/{kind}', [VideoProjectsController::class, 'judgeDesignDna'])->whereIn('kind', ['novelty', 'plausibility'])->name('video-projects.design-dna-judge');
            //tạo phân cảnh
            Route::post('/{id}/design', [VideoProjectsController::class, 'vesselDesign'])->name('video-projects.vessel-design');
            Route::post('/{id}/design/reset', [VideoProjectsController::class, 'resetVesselDesign'])->name('video-projects.vessel-design-reset');
            Route::get('/{id}/proxy', [VideoProjectsController::class, 'proxy'])->name('video-projects.proxy');
            Route::post('/{id}/proxy/build', [VideoProjectsController::class, 'buildProxy'])->name('video-projects.proxy-build');
            Route::post('/{id}/proxy/reference/{action}', [VideoProjectsController::class, 'proxyReference'])->whereIn('action', ['propose', 'polygon', 'confirm', 'landmarks', 'camera'])->name('video-projects.proxy-reference');
            Route::post('/{id}/proxy/approve', [VideoProjectsController::class, 'approveProxy'])->name('video-projects.proxy-approve');
            Route::post('/{id}/model-sheet/qa', [VideoProjectsController::class, 'qaModelSheet'])->name('video-projects.model-sheet-qa');
            Route::post('/{id}/model-sheet/render',[VideoProjectsController::class, 'renderModelSheet'])->name('video-projects.model-sheet-render');
            Route::post('/{id}/model-sheet/approve', [VideoProjectsController::class, 'approveModelSheet'])->name('video-projects.model-sheet-approve');
            Route::post('/{id}/model-sheet/reject', [VideoProjectsController::class, 'rejectModelSheet'])->name('video-projects.model-sheet-reject');
            Route::post('/{id}/model-sheet/process', [VideoProjectsController::class, 'processModelSheet'])->name('video-projects.model-sheet-process');

            Route::middleware('video.v4')->group(function () {
            Route::post('/{id}/concept', [VideoProjectsController::class, 'concept'])->name('video-projects.concept');
            Route::post('/{id}/concept/reset', [VideoProjectsController::class, 'resetConcept'])->name('video-projects.concept-reset');
            Route::post('/{id}/screenplay', [VideoProjectsController::class, 'screenplay'])->name('video-projects.screenplay');
            // viết kịch bản
            Route::post('/{id}/screenplay/foundation', [VideoProjectsController::class, 'screenplayFoundation'])->name('video-projects.screenplay-foundation');
            Route::post('/{id}/screenplay/foundation/reset', [VideoProjectsController::class, 'resetScreenplayFoundation'])->name('video-projects.screenplay-foundation-reset');
            Route::post('/{id}/screenplay/characters', [VideoProjectsController::class, 'screenplayCharacters'])->name('video-projects.screenplay-characters');
            Route::post('/{id}/screenplay/characters/reset', [VideoProjectsController::class, 'resetScreenplayCharacters'])->name('video-projects.screenplay-characters-reset');
            Route::post('/{id}/screenplay/locations', [VideoProjectsController::class, 'screenplayLocations'])->name('video-projects.screenplay-locations');
            Route::post('/{id}/screenplay/locations/reset', [VideoProjectsController::class, 'resetScreenplayLocations'])->name('video-projects.screenplay-locations-reset');
            Route::post('/{id}/screenplay/reset', [VideoProjectsController::class, 'resetScreenplay'])->name('video-projects.screenplay-reset');
            Route::post('/{id}/screenplay/approve', [VideoProjectsController::class, 'approveScreenplay'])->name('video-projects.screenplay-approve');
            Route::post('/{id}/screenplay/select', [VideoProjectsController::class, 'selectScreenplay'])->name('video-projects.screenplay-select');
            Route::post('/{id}/anchor-image', [VideoProjectsController::class, 'createAnchorImage'])->name('video-projects.anchor-image');
            Route::post('/{id}/design-images/{imageId}/enqueue', [VideoProjectsController::class, 'renderDesignImage'])->name('video-projects.design-image-enqueue');
            Route::post('/{id}/anchor/approve', [VideoProjectsController::class, 'approveAnchor'])->name('video-projects.anchor-approve');
            Route::post('/{id}/reference/approve', [VideoProjectsController::class, 'approveReference'])->name('video-projects.reference-approve');
            Route::post('/{id}/reference/delete', [VideoProjectsController::class, 'deleteReference'])->name('video-projects.reference-delete');
            Route::post('/{id}/reference/prompt', [VideoProjectsController::class, 'writeReferencePrompt'])->name('video-projects.reference-prompt');
            Route::get('/{id}/reference/sheet-preview', [VideoProjectsController::class, 'referenceSheetPreview'])->name('video-projects.reference-sheet-preview');
            Route::post('/{id}/reference/lock',[VideoProjectsController::class, 'lockReferences'])->name('video-projects.reference-lock');
            Route::post('/{id}/reference/plan', [VideoProjectsController::class, 'writeReferencePlan'])->name('video-projects.reference-plan');
            Route::post('/{id}/reference/plan/decide', [VideoProjectsController::class, 'decideReferencePlanItem'])->name('video-projects.reference-plan-decide');
            Route::post('/{id}/reference/plan/render', [VideoProjectsController::class, 'renderReferencePlanItem'])->name('video-projects.reference-plan-render');
            Route::match(['get', 'post'], '/{id}/reference', [VideoProjectsController::class, 'reference'])->name('video-projects.reference');
            Route::post('/{id}/environment/approve', [VideoProjectsController::class, 'approveEnvironment'])->name('video-projects.environment-approve');
            Route::match(['get', 'post'], '/{id}/environment', [VideoProjectsController::class, 'environment'])->name('video-projects.environment');
            Route::get('/{id}/scene/{scene?}/{step?}', [VideoProjectsController::class, 'scene'])->name('video-projects.scene');
            Route::post('/{id}/storyboard/generate', [VideoProjectsController::class, 'generateStoryboard'])->name('video-projects.storyboard-generate');
            Route::post('/{id}/storyboard/approve', [VideoProjectsController::class, 'approveStoryboard'])->name('video-projects.storyboard-approve');
            Route::post('/{id}/storyboard/recheck', [VideoProjectsController::class, 'recheckStoryboard'])->name('video-projects.storyboard-recheck');
            Route::post('/{id}/storyboard/findings/accept', [VideoProjectsController::class, 'acceptStoryboardFinding'])->name('video-projects.storyboard-finding-accept');
            Route::post('/{id}/scenes/{sceneId}/reference-confirm', [VideoProjectsController::class, 'confirmShotReference'])->name('video-projects.scene-reference-confirm');            Route::get('/{id}/scenes/{sceneId}/keyframe/preview', [VideoProjectsController::class, 'sceneKeyframePreview'])->name('video-projects.scene-keyframe-preview');
            Route::post('/{id}/scenes/{sceneId}/keyframe', [VideoProjectsController::class, 'renderSceneKeyframe'])->name('video-projects.scene-keyframe-render');
            Route::post('/{id}/scenes/{sceneId}/references', [VideoProjectsController::class, 'saveSceneReferences'])->name('video-projects.scene-references');
            Route::post('/{id}/scenes/{sceneId}/keyframe/use', [VideoProjectsController::class, 'chooseSceneKeyframe'])->name('video-projects.scene-keyframe-use');
            Route::post('/{id}/scenes/{sceneId}/film-views', [VideoProjectsController::class, 'chooseFilmViews'])->name('video-projects.film-reference-views');
            Route::get('/{id}/render-video', [VideoProjectsController::class, 'clips'])->name('video-projects.render-video');
            Route::get('/{id}/final-composition-preview', [VideoProjectsController::class, 'finalCompositionPreview'])->name('video-projects.final-composition-preview');
            Route::post('/{id}/final-composition/render', [VideoProjectsController::class, 'renderFinalComposition'])->name('video-projects.final-render');
            Route::get('/{id}/final-composition/{final}/file', [VideoProjectsController::class, 'finalCompositionFile'])->name('video-projects.final-file');
            Route::post('/{id}/scenes/{sceneId}/clip', [VideoProjectsController::class, 'renderSceneClip'])->name('video-projects.scene-clip-render');            Route::post('/{id}/scene-clips/{shotId}/select', [VideoProjectsController::class, 'selectSceneClip'])->name('video-projects.scene-clip-select');
            Route::post('/{id}/scene-clips/{render}/poll', [VideoProjectsController::class, 'pollSceneClip'])->name('video-projects.scene-clip-poll');
            Route::get('/{id}/scene-clips/{render}/file', [VideoProjectsController::class, 'sceneClipFile'])->name('video-projects.scene-clip-file');
            Route::post('/{id}/scene-keyframes/{image}/retry', [VideoProjectsController::class, 'retrySceneKeyframe'])->name('video-projects.scene-keyframe-retry');
            Route::post('/{id}/scene-keyframes/{image}/approve', [VideoProjectsController::class, 'approveSceneKeyframe'])->name('video-projects.scene-keyframe-approve');
            });
        });

        Route::get('/video-artifacts/{artifact}', [VideoArtifactController::class, 'show'])->name('video-artifacts.show');

        // article
        Route::group(['prefix' => 'article'], function () {
            Route::get('/', [ArticleController::class, 'index'])->name('article.index');
            Route::post('/generate-all', [ArticleController::class, 'generateAll'])->name('article.generateAll');
            Route::post('/generate-one', [ArticleController::class, 'generateOne'])->name('article.generateOne');
            Route::post('/publish-all', [ArticleController::class, 'publishAll'])->name('article.publishAll');
            Route::delete('/delete-all', [ArticleController::class, 'destroyAll'])->name('article.destroyAll');
            Route::delete('/delete-selected', [ArticleController::class, 'destroySelected'])->name('article.destroySelected');
            Route::post('/cache-clear', [ArticleController::class, 'clearCache'])->name('article.clearCache');
            Route::post('/store-manual', [ArticleController::class, 'storeManual'])->name('article.storeManual');
            Route::post('/send-to-claude', [ArticleController::class, 'sendToClaude'])->name('article.sendToClaude');
            Route::post('/synthesize', [ArticleController::class, 'synthesize'])->name('article.synthesize');
            Route::post('/{article}/publish', [ArticleController::class, 'publish'])->name('article.publish');
            Route::post('/{article}/unpublish', [ArticleController::class, 'unpublish'])->name('article.unpublish');
            Route::get('/{article}/search-images', [ArticleController::class, 'searchImages'])->name('article.searchImages');
            Route::post('/{article}/update-thumbnail', [ArticleController::class, 'updateThumbnail'])->name('article.updateThumbnail');
            Route::delete('/{article}', [ArticleController::class, 'destroy'])->name('article.destroy');
            Route::get('/{article}', [ArticleController::class, 'show'])->name('article.show');
        });

        // Raw articles (Google News fetch → manual AI generate)
        Route::group(['prefix' => 'raw-article'], function () {
            Route::get('/', [RawArticleController::class, 'index'])->name('raw-article.index');
            Route::post('/fetch-all', [RawArticleController::class, 'fetchAll'])->name('raw-article.fetchAll');
            Route::post('/fetch-one', [RawArticleController::class, 'fetchOne'])->name('raw-article.fetchOne');
            Route::post('/clear-refetch', [RawArticleController::class, 'clearRefetch'])->name('raw-article.clearRefetch');
            Route::post('/{rawArticle}/save', [RawArticleController::class, 'save'])->name('raw-article.save');
            Route::delete('/{rawArticle}', [RawArticleController::class, 'destroy'])->name('raw-article.destroy');
        });

        // Keywords
        Route::group(['prefix' => 'keyword'], function () {
            Route::get('/', [KeywordController::class, 'index'])->name('keyword.index');
            Route::post('/', [KeywordController::class, 'store'])->name('keyword.store');
            Route::get('/{keyword}', [KeywordController::class, 'show'])->name('keyword.show');
            Route::put('/{keyword}', [KeywordController::class, 'update'])->name('keyword.update');
            Route::delete('/{keyword}', [KeywordController::class, 'destroy'])->name('keyword.destroy');
            Route::patch('/{keyword}/toggle', [KeywordController::class, 'toggleActive'])->name('keyword.toggle');
        });

        // News Sources (trusted / blocked domains)
        Route::group(['prefix' => 'news-source'], function () {
            Route::get('/', [NewsSourceController::class, 'index'])->name('news-source.index');
            Route::post('/', [NewsSourceController::class, 'store'])->name('news-source.store');
            Route::get('/{newsSource}', [NewsSourceController::class, 'show'])->name('news-source.show');
            Route::put('/{newsSource}', [NewsSourceController::class, 'update'])->name('news-source.update');
            Route::delete('/{newsSource}', [NewsSourceController::class, 'destroy'])->name('news-source.destroy');
            Route::patch('/{newsSource}/toggle', [NewsSourceController::class, 'toggleActive'])->name('news-source.toggle');
        });

        Route::post('/getlink', [GetLinkController::class, 'getLink']);
        Route::get('/get-tags', [GetTagController::class, 'getTags']);
        Route::get('/modal-confirm', [ModalConfirmController::class, 'modalConfirm'])->name('modal.confirm');
    });

Route::middleware('auth:sanctum')->get('/posts', function () {
    return \App\Models\Post::latest()->get();
});
