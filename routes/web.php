<?php

use App\Http\Controllers\ArticleController;
use App\Http\Controllers\KeywordController;
use App\Http\Controllers\NewsSourceController;
use App\Http\Controllers\RawArticleController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdvertisementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\FontController;
use App\Http\Controllers\GetLinkController;
use App\Http\Controllers\GetTagController;
use App\Http\Controllers\InforDomainsController;
use App\Http\Controllers\ModalConfirmController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TrendingController;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;

// Route::get('/', function () {
//     return view('welcome');
// });

Route::fallback(function () {
    $path = public_path('astro/index.html');
    if (File::exists($path)) {
        return Response::file($path);
    } else {
        abort(404);
    }
});

Route::group(['prefix' => '/'], function () {
    // Login
    Route::match(['get', 'post'], '/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login');
    // Logout
    Route::get('/logout', [AuthController::class, 'logout'])
        ->middleware('auth')
        ->name('logout');
});

Route::group(
    [
        "prefix" => "admin",
        "middleware" => "auth",
    ],
    function() {
    // Admin
    Route::get('/', [AdminController::class, 'index'])->name('admin.index');
    Route::match(['get', 'post'], '/add', [AdminController::class, 'add'])->name('admin.add');
    Route::match(['get', 'post'], '/update/{id}', [AdminController::class, 'update'])->name('admin.update');
    Route::match(['get', 'post'], '/delete/{id}', [AdminController::class, 'delete'])->name('admin.delete');
    Route::match(['get', 'post'], '/detail/{id}', [AdminController::class, 'detail'])->name('admin.detail');
    // category
    Route::group(["prefix" => "category"], function () {
        Route::get("/", [CategoryController::class, "index"])->name("admin.category.index");
        Route::match(["get", "post"], "/add", [CategoryController::class, "add"])->name("admin.category.add");
        Route::match(["get", "post"], "/update/{id}", [CategoryController::class, "update"])->name("admin.category.update");
        Route::match(["get", "post"], "/delete", [CategoryController::class, "delete"])->name("admin.category.delete");
        Route::match(["get", "post"], "/detail/{id}", [CategoryController::class, "detail"])->name("admin.category.detail");
    });
    // tag
    Route::group(["prefix" => "tag"], function () {
        Route::get("/", [TagController::class, "index"])->name("tag.index");
        Route::match(["get", "post"], "/add", [TagController::class, "add"])->name("tag.add");
        Route::match(["get", "post"], "/update/{id}", [TagController::class, "update"])->name("tag.update");
        Route::match(["get", "post"], "/delete", [TagController::class, "delete"])->name("tag.delete");
        Route::match(["get", "post"], "/detail/{id}", [TagController::class, "detail"])->name("tag.detail");
    });
    // post
    Route::group(["prefix" => "post"], function () {
        Route::get("/", [PostController::class, "index"])->name("post.index");
        Route::match(["get", "post"], "/add", [PostController::class, "add"])->name("post.add");
        Route::match(["get", "post"], "/addpost", [PostController::class, "addPost"])->name("post.addpost");
        Route::match(["get", "post"], "/update/{id}", [PostController::class, "update"])->name("post.update");
        Route::match(["get", "post"], "/delete", [PostController::class, "delete"])->name("post.delete");
        Route::match(["get", "post"], "/post/{slug}", [PostController::class, "detail"])->name("post.detail");
        // Route::match(["get", "post"], "/detail/{slug}", [PostController::class, "detail"])->name("post.detail");
    });

    // infor domain
    Route::group(["prefix" => "domain"], function () {
        Route::get("/", [InforDomainsController::class, "index"])->name("domain.index");
        Route::match(["get", "post"], "/add", [InforDomainsController::class, "add"])->name("domain.add");
        Route::match(["get", "post"], "/update/{id}", [InforDomainsController::class, "update"])->name("domain.update");
        Route::match(["get", "post"], "/delete", [InforDomainsController::class, "delete"])->name("domain.delete");
        Route::match(["get", "post"], "/detail/{id}", [InforDomainsController::class, "detail"])->name("domain.detail");
    });

    // ads
    Route::group(["prefix" => "ads"], function () {
        Route::get("/", [AdvertisementController::class, "index"])->name("ads.index");
        Route::match(["get", "post"], "/add", [AdvertisementController::class, "add"])->name("ads.add");
        Route::match(["get", "post"], "/update/{id}", [AdvertisementController::class, "update"])->name("ads.update");
        Route::match(["get", "post"], "/delete", [AdvertisementController::class, "delete"])->name("ads.delete");
        Route::match(["get", "post"], "/detail/{id}", [AdvertisementController::class, "detail"])->name("ads.detail");
    });

    // convert font
    Route::group(["prefix" => "font"], function () {
        Route::get("/", [FontController::class, "index"])->name("font.index");
        Route::match(["get", "post"], "/add", [FontController::class, "add"])->name("font.add");
        Route::match(["get", "post"], "/update/{id}", [FontController::class, "update"])->name("font.update");
        Route::match(["get", "post"], "/delete", [FontController::class, "delete"])->name("font.delete");
        Route::match(["get", "post"], "/detail/{id}", [FontController::class, "detail"])->name("font.detail");
    });

    // Domains
    Route::group(["prefix" => "website"], function () {
        Route::get("/", [DomainController::class, "index"])->name("website.index");
        Route::match(["get", "post"], "/add", [DomainController::class, "add"])->name("website.add");
        Route::match(["get", "post"], "/update/{id}", [DomainController::class, "update"])->name("website.update");
        Route::match(["get", "post"], "/delete", [DomainController::class, "delete"])->name("website.delete");
        Route::match(["get", "post"], "/detail/{id}", [DomainController::class, "detail"])->name("website.detail");
        Route::post('{domain}/api-key', [DomainController::class,'generateApiKey']);
    });

    // article
    Route::group(['prefix' => 'article'], function () {
        Route::get('/',                              [ArticleController::class, 'index'])      ->name('article.index');
        Route::post('/generate-all',                 [ArticleController::class, 'generateAll'])->name('article.generateAll');
        Route::post('/generate-one',                 [ArticleController::class, 'generateOne'])->name('article.generateOne');
        Route::post('/publish-all',                  [ArticleController::class, 'publishAll']) ->name('article.publishAll');
        Route::delete('/delete-all',                 [ArticleController::class, 'destroyAll'])      ->name('article.destroyAll');
        Route::delete('/delete-selected',            [ArticleController::class, 'destroySelected']) ->name('article.destroySelected');
        Route::post('/cache-clear',                  [ArticleController::class, 'clearCache'])    ->name('article.clearCache');
        Route::post('/send-to-claude',               [ArticleController::class, 'sendToClaude']) ->name('article.sendToClaude');
        Route::post('/synthesize',                   [ArticleController::class, 'synthesize'])   ->name('article.synthesize');
        Route::post('/{article}/publish',            [ArticleController::class, 'publish'])    ->name('article.publish');
        Route::post('/{article}/unpublish',          [ArticleController::class, 'unpublish'])  ->name('article.unpublish');
        Route::delete('/{article}',                  [ArticleController::class, 'destroy'])    ->name('article.destroy');
        Route::get('/{article}',                     [ArticleController::class, 'show'])       ->name('article.show');
    });

    // Raw articles (Google News fetch → manual AI generate)
    Route::group(['prefix' => 'raw-article'], function () {
        Route::get('/',                                [RawArticleController::class, 'index'])           ->name('raw-article.index');
        Route::post('/fetch-all',                      [RawArticleController::class, 'fetchAll'])         ->name('raw-article.fetchAll');
        Route::post('/fetch-one',                      [RawArticleController::class, 'fetchOne'])         ->name('raw-article.fetchOne');
        Route::post('/generate-keyword',               [RawArticleController::class, 'generateKeyword'])  ->name('raw-article.generateKeyword');
        Route::post('/generate-selected',              [RawArticleController::class, 'generateSelected']) ->name('raw-article.generateSelected');
        Route::post('/clear-refetch',                  [RawArticleController::class, 'clearRefetch'])     ->name('raw-article.clearRefetch');
        Route::post('/{rawArticle}/save',              [RawArticleController::class, 'save'])             ->name('raw-article.save');
        Route::post('/{rawArticle}/generate',          [RawArticleController::class, 'generate'])         ->name('raw-article.generate');
        Route::post('/{rawArticle}/retry',             [RawArticleController::class, 'retry'])            ->name('raw-article.retry');
        Route::delete('/{rawArticle}',                 [RawArticleController::class, 'destroy'])          ->name('raw-article.destroy');
    });

    // Keywords
    Route::group(['prefix' => 'keyword'], function () {
        Route::get('/',               [KeywordController::class, 'index'])        ->name('keyword.index');
        Route::post('/',              [KeywordController::class, 'store'])        ->name('keyword.store');
        Route::get('/{keyword}',      [KeywordController::class, 'show'])        ->name('keyword.show');
        Route::put('/{keyword}',      [KeywordController::class, 'update'])      ->name('keyword.update');
        Route::delete('/{keyword}',   [KeywordController::class, 'destroy'])     ->name('keyword.destroy');
        Route::patch('/{keyword}/toggle', [KeywordController::class, 'toggleActive'])->name('keyword.toggle');
    });

    // News Sources (trusted / blocked domains)
    Route::group(['prefix' => 'news-source'], function () {
        Route::get('/',                      [NewsSourceController::class, 'index'])       ->name('news-source.index');
        Route::post('/',                     [NewsSourceController::class, 'store'])       ->name('news-source.store');
        Route::get('/{newsSource}',          [NewsSourceController::class, 'show'])        ->name('news-source.show');
        Route::put('/{newsSource}',          [NewsSourceController::class, 'update'])      ->name('news-source.update');
        Route::delete('/{newsSource}',       [NewsSourceController::class, 'destroy'])     ->name('news-source.destroy');
        Route::patch('/{newsSource}/toggle', [NewsSourceController::class, 'toggleActive'])->name('news-source.toggle');
    });

    Route::post("/getlink", [GetLinkController::class, "getLink"]);
    Route::get("/get-tags", [GetTagController::class, "getTags"]);
    Route::get("/modal-confirm", [ModalConfirmController::class, "modalConfirm"])->name("modal.confirm");
    Route::get("/trending", [TrendingController::class, "index"])->name("trending.index");
});

Route::middleware('auth:sanctum')->get('/posts', function (Request $request) {
    return \App\Models\Post::latest()->get();
});

// Route::get('/test-domain', function () {
//     dd(
//         function_exists('currentDomain'),
//         currentDomain(),
//         request()->getHost()
//     );
// });
