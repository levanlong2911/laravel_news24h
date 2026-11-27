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
use App\Http\Controllers\SocialPublishingController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\VideoArtifactController;
use App\Http\Controllers\Video\DesignController;
use App\Http\Controllers\Video\DesignDnaController;
use App\Http\Controllers\Video\FinalCompositionController;
use App\Http\Controllers\Video\InspirationController;
use App\Http\Controllers\Video\KeyframeController;
use App\Http\Controllers\Video\LegacyVideoController;
use App\Http\Controllers\Video\LocationController;
use App\Http\Controllers\Video\ReferenceController;
use App\Http\Controllers\Video\RenderVideoController;
use App\Http\Controllers\Video\SceneController;
use App\Http\Controllers\Video\ScriptController;
use App\Http\Controllers\Video\ShotController;
use App\Http\Controllers\Video\VideoProjectController;
use App\Http\Controllers\Video\VideoTaskController;
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
            Route::get('/', [VideoProjectController::class, 'index'])->name('video-projects.index');
            Route::post('/from-article/{article}', [VideoProjectController::class, 'store'])->name('video-projects.store');
            Route::get('/{id}/anchor', [VideoProjectController::class, 'show'])->name('video-projects.anchor');
            Route::get('/{id}/tasks', [VideoTaskController::class, 'index'])->name('video-projects.tasks');
            Route::get('/{id}/planning-stages/{stage}/status', [VideoTaskController::class, 'stage'])->name('video-projects.stage-status');

            // phase 1: inspiration
            Route::group(['prefix' => '{id}/inspiration'], function () {
                Route::post('/', [InspirationController::class, 'run'])->name('video-projects.inspiration');
                Route::post('/reset', [InspirationController::class, 'reset'])->name('video-projects.inspiration-reset');
            });
            // phase 2: design dna
            Route::group(['prefix' => '{id}/design-dna'], function () {
                Route::post('/', [DesignDnaController::class, 'run'])->name('video-projects.design-dna');
                Route::post('/reset', [DesignDnaController::class, 'reset'])->name('video-projects.design-dna-reset');
            });
            // phase 3: thiết kế và model sheet
            Route::group(['prefix' => '{id}'], function () {
                Route::post('/design', [DesignController::class, 'run'])->name('video-projects.vessel-design');
                Route::post('/design/reset', [DesignController::class, 'reset'])->name('video-projects.vessel-design-reset');
                Route::post('/model-sheet/qa', [DesignController::class, 'sheetQa'])->name('video-projects.model-sheet-qa');
                Route::post('/model-sheet/qa/reset', [DesignController::class, 'sheetQaReset'])->name('video-projects.model-sheet-qa-reset');
                Route::post('/model-sheet/render', [DesignController::class, 'sheetRender'])->name('video-projects.model-sheet-render');
                Route::post('/model-sheet/approve', [DesignController::class, 'sheetApprove'])->name('video-projects.model-sheet-approve');
                Route::post('/model-sheet/reject', [DesignController::class, 'sheetReject'])->name('video-projects.model-sheet-reject');
                Route::post('/model-sheet/process', [DesignController::class, 'sheetProcess'])->name('video-projects.model-sheet-process');
            });
            // phase 4: kịch bản
            Route::group(['prefix' => '{id}/script'], function () {
                Route::post('/', [ScriptController::class, 'run'])->name('video-projects.script');
                Route::post('/reset', [ScriptController::class, 'reset'])->name('video-projects.script-reset');
                Route::post('/brief', [ScriptController::class, 'brief'])->name('video-projects.script-brief');
                Route::post('/approve', [ScriptController::class, 'approve'])->name('video-projects.script-approve');
            });
            // phase 5: địa điểm và bối cảnh
            Route::group(['prefix' => '{id}/locations'], function () {
                Route::get('/', [LocationController::class, 'index'])->name('video-projects.locations');
                Route::post('/', [LocationController::class, 'run'])->name('video-projects.locations-write');
                Route::post('/reset', [LocationController::class, 'reset'])->name('video-projects.locations-reset');
                Route::post('/render', [LocationController::class, 'render'])->name('video-projects.locations-render');
                Route::post('/choose', [LocationController::class, 'choose'])->name('video-projects.locations-choose');
                Route::post('/standard', [LocationController::class, 'standard'])->name('video-projects.locations-standard');
            });
            // phase 6: scene và continuity
            Route::group(['prefix' => '{id}'], function () {
                Route::get('/scene/{scene?}/{step?}', [SceneController::class, 'index'])->name('video-projects.scene');
                Route::post('/scenes', [SceneController::class, 'run'])->name('video-projects.scenes-write');
                Route::post('/scenes/reset', [SceneController::class, 'reset'])->name('video-projects.scenes-reset');
            });
            // phase 7: kế hoạch shot
            Route::group(['prefix' => '{id}/shots'], function () {
                Route::post('/', [ShotController::class, 'run'])->name('video-projects.shots-write');
                Route::post('/reset', [ShotController::class, 'reset'])->name('video-projects.shots-reset');
                Route::post('/approve', [ShotController::class, 'approve'])->name('video-projects.shots-approve');
            });
            // phase 8: reference và ảnh nhân vật
            Route::group(['prefix' => '{id}/reference'], function () {
                Route::match(['get', 'post'], '/', [ReferenceController::class, 'index'])->name('video-projects.reference');
                Route::post('/parts-detail/{action}', [ReferenceController::class, 'partsDetail'])->whereIn('action', ['plan', 'layout', 'review', 'accept', 'render', 'process', 'approve', 'reject', 'revoke', 'answer'])->name('video-projects.parts-detail');
                Route::post('/characters/{character}/{action}', [ReferenceController::class, 'character'])->where('character', '[A-Za-z0-9_-]+')->whereIn('action', ['settings', 'render', 'process', 'approve', 'reject', 'revoke'])->name('video-projects.character-reference');
            });
            // phase 9: keyframe
            Route::group(['prefix' => '{id}/keyframes'], function () {
                Route::get('/', [KeyframeController::class, 'index'])->name('video-projects.keyframes');
                Route::post('/{shot}/{action}', [KeyframeController::class, 'action'])->where('shot', 'SC\d+_SH\d+')->whereIn('action', ['source', 'source-reset', 'source-off', 'source-on', 'settings', 'render', 'approve', 'revoke', 'frame', 'edit-prompt', 'edit-render', 'prompt', 'prompt-approve'])->name('video-projects.keyframe-action');
            });
            // phase 10: render video
            Route::group(['prefix' => '{id}'], function () {
                Route::get('/render-video', [RenderVideoController::class, 'index'])->name('video-projects.render-video');
                Route::post('/render-video/{shot}/{action}', [RenderVideoController::class, 'action'])->where('shot', 'SC\d+_SH\d+')->whereIn('action', ['prompt', 'prompt-approve', 'input', 'settings', 'render', 'approve', 'reject', 'revoke', 'poll'])->name('video-projects.clip-prompt-action');
                Route::get('/render-video/{shot}/review/{render}', [RenderVideoController::class, 'review'])->where('shot', 'SC\d+_SH\d+')->whereUuid('render')->name('video-projects.clip-review');
                Route::get('/render-video/stills/{render}/{key}/{index}', [RenderVideoController::class, 'still'])->whereUuid('render')->where('key', '[a-f0-9]{64}')->whereNumber('index')->name('video-projects.clip-still');
                Route::post('/scenes/{sceneId}/clip', [RenderVideoController::class, 'sceneRender'])->name('video-projects.scene-clip-render');
                Route::post('/scene-clips/{shotId}/select', [RenderVideoController::class, 'sceneSelect'])->name('video-projects.scene-clip-select');
                Route::post('/scene-clips/{render}/poll', [RenderVideoController::class, 'scenePoll'])->name('video-projects.scene-clip-poll');
                Route::get('/scene-clips/{render}/file', [RenderVideoController::class, 'sceneFile'])->name('video-projects.scene-clip-file');
            });
            // phase 11: final composition
            Route::group(['prefix' => '{id}'], function () {
                Route::get('/final-composition-preview', [FinalCompositionController::class, 'index'])->name('video-projects.final-composition-preview');
                Route::post('/final-composition/render', [FinalCompositionController::class, 'render'])->name('video-projects.final-render');
                Route::get('/final-composition/{final}/file', [FinalCompositionController::class, 'file'])->name('video-projects.final-file');
                Route::get('/final-composition/{final}/status', [FinalCompositionController::class, 'status'])->name('video-projects.final-status');
                Route::post('/final-composition/{final}/approve', [FinalCompositionController::class, 'approve'])->name('video-projects.final-approve');
                // QC cua mot ban final, tren chinh man Final Composition: do lai file, review noi dung, neu va xu ly finding, yeu cau sua. Chi co o du an ma ban phat hanh khai action QC.
                Route::post('/final-composition/{final}/qc', [FinalCompositionController::class, 'qc'])->name('video-projects.final-qc');
                Route::post('/final-composition/{final}/qc/review', [FinalCompositionController::class, 'qcReview'])->name('video-projects.final-qc-review');
                Route::post('/final-composition/{final}/qc/finding', [FinalCompositionController::class, 'qcFinding'])->name('video-projects.final-qc-finding');
                Route::post('/final-composition/{final}/qc/finding/{finding}', [FinalCompositionController::class, 'qcSettle'])->where('finding', 'FD[0-9]{3}')->name('video-projects.final-qc-settle');
                Route::post('/final-composition/{final}/request-changes', [FinalCompositionController::class, 'requestChanges'])->name('video-projects.final-request-changes');
                // Cac dau ra da luu cua mot ban final, tai ve duoi dang JSON: danh sach quyet dinh dung, timeline, bao cao QC, quyet dinh duyet. Chi doc.
                Route::get('/final-composition/{final}/document/{kind}', [FinalCompositionController::class, 'document'])->whereIn('kind', ['edit-decisions', 'timeline', 'qc-report', 'approval'])->name('video-projects.final-document');
            });

            // luồng du thuyền cũ (v6.3): reference cũ, environment
            Route::group(['prefix' => '{id}', 'middleware' => 'video.v4'], function () {
                Route::post('/design-images/{imageId}/enqueue', [LegacyVideoController::class, 'renderDesignImage'])->name('video-projects.design-image-enqueue');
                Route::post('/reference/approve', [LegacyVideoController::class, 'approveReference'])->name('video-projects.reference-approve');
                Route::post('/reference/delete', [LegacyVideoController::class, 'deleteReference'])->name('video-projects.reference-delete');
                Route::post('/reference/prompt', [LegacyVideoController::class, 'writeReferencePrompt'])->name('video-projects.reference-prompt');
                Route::get('/reference/sheet-preview', [LegacyVideoController::class, 'referenceSheetPreview'])->name('video-projects.reference-sheet-preview');
                Route::post('/reference/lock', [LegacyVideoController::class, 'lockReferences'])->name('video-projects.reference-lock');
                Route::post('/reference/plan', [LegacyVideoController::class, 'writeReferencePlan'])->name('video-projects.reference-plan');
                Route::post('/reference/plan/decide', [LegacyVideoController::class, 'decideReferencePlanItem'])->name('video-projects.reference-plan-decide');
                Route::post('/reference/plan/render', [LegacyVideoController::class, 'renderReferencePlanItem'])->name('video-projects.reference-plan-render');
                Route::post('/environment/approve', [LegacyVideoController::class, 'approveEnvironment'])->name('video-projects.environment-approve');
                Route::match(['get', 'post'], '/environment', [LegacyVideoController::class, 'environment'])->name('video-projects.environment');
            });
            // luồng du thuyền cũ (v6.3): storyboard và keyframe theo scene
            Route::group(['prefix' => '{id}', 'middleware' => ['video.shot-plan', 'video.v4']], function () {
                Route::post('/storyboard/generate', [LegacyVideoController::class, 'generateStoryboard'])->name('video-projects.storyboard-generate');
                Route::post('/storyboard/approve', [LegacyVideoController::class, 'approveStoryboard'])->name('video-projects.storyboard-approve');
                Route::post('/storyboard/recheck', [LegacyVideoController::class, 'recheckStoryboard'])->name('video-projects.storyboard-recheck');
                Route::post('/storyboard/findings/accept', [LegacyVideoController::class, 'acceptStoryboardFinding'])->name('video-projects.storyboard-finding-accept');
                Route::post('/scenes/{sceneId}/reference-confirm', [LegacyVideoController::class, 'confirmShotReference'])->name('video-projects.scene-reference-confirm');
                Route::get('/scenes/{sceneId}/keyframe/preview', [LegacyVideoController::class, 'sceneKeyframePreview'])->name('video-projects.scene-keyframe-preview');
                Route::post('/scenes/{sceneId}/keyframe', [LegacyVideoController::class, 'renderSceneKeyframe'])->name('video-projects.scene-keyframe-render');
                Route::post('/scenes/{sceneId}/references', [LegacyVideoController::class, 'saveSceneReferences'])->name('video-projects.scene-references');
                Route::post('/scenes/{sceneId}/keyframe/use', [LegacyVideoController::class, 'chooseSceneKeyframe'])->name('video-projects.scene-keyframe-use');
                Route::post('/scenes/{sceneId}/film-views', [LegacyVideoController::class, 'chooseFilmViews'])->name('video-projects.film-reference-views');
                Route::post('/scene-keyframes/{image}/retry', [LegacyVideoController::class, 'retrySceneKeyframe'])->name('video-projects.scene-keyframe-retry');
                Route::post('/scene-keyframes/{image}/approve', [LegacyVideoController::class, 'approveSceneKeyframe'])->name('video-projects.scene-keyframe-approve');
            });
        });

        // Phase 12 — Social Publishing: goi noi dung cho tung kenh tu phim da duyet. Man hinh va controller rieng;
        // moi thao tac kiem co bat chuc nang, quyen tren du an va quyen theo vai tro o server (SocialPublishingController::open).
        Route::group(['prefix' => 'video-projects/{id}/social-publishing'], function () {
            Route::get('/', [SocialPublishingController::class, 'index'])->name('video-projects.social-publishing');
            Route::post('/channels', [SocialPublishingController::class, 'storeChannel'])->name('video-projects.social-channel-store');
            Route::post('/channels/{channel}', [SocialPublishingController::class, 'updateChannel'])->whereUuid('channel')->name('video-projects.social-channel-update');
            Route::post('/channels/{channel}/archive', [SocialPublishingController::class, 'archiveChannel'])->whereUuid('channel')->name('video-projects.social-channel-archive');
            Route::get('/connect/{platform}', [SocialPublishingController::class, 'connect'])->whereIn('platform', ['youtube', 'facebook'])->name('video-projects.social-connect');
            Route::post('/connections/{connection}/disconnect', [SocialPublishingController::class, 'disconnect'])->whereUuid('connection')->name('video-projects.social-disconnect');
            Route::post('/packages', [SocialPublishingController::class, 'storePackage'])->name('video-projects.social-package-store');
            Route::post('/packages/{package}/save', [SocialPublishingController::class, 'savePackage'])->whereUuid('package')->name('video-projects.social-package-save');
            Route::post('/packages/{package}/rebase', [SocialPublishingController::class, 'rebasePackage'])->whereUuid('package')->name('video-projects.social-package-rebase');
            // Hai nut tinh phi: AI viet noi dung va AI review. Chi chay khi nguoi dung bam.
            Route::post('/packages/{package}/write', [SocialPublishingController::class, 'writePackage'])->whereUuid('package')->name('video-projects.social-package-write');
            Route::post('/packages/{package}/review', [SocialPublishingController::class, 'reviewPackage'])->whereUuid('package')->name('video-projects.social-package-review');
            Route::get('/packages/{package}/frame', [SocialPublishingController::class, 'frame'])->whereUuid('package')->name('video-projects.social-frame');
            Route::post('/packages/{package}/cover', [SocialPublishingController::class, 'makeCover'])->whereUuid('package')->name('video-projects.social-cover-make');
            Route::post('/packages/{package}/cover/select', [SocialPublishingController::class, 'selectCover'])->whereUuid('package')->name('video-projects.social-cover-select');
            Route::get('/covers/{artifact}', [SocialPublishingController::class, 'cover'])->whereUuid('artifact')->name('video-projects.social-cover');
            Route::post('/packages/{package}/approve', [SocialPublishingController::class, 'approve'])->whereUuid('package')->name('video-projects.social-package-approve');
            Route::post('/packages/{package}/revoke', [SocialPublishingController::class, 'revoke'])->whereUuid('package')->name('video-projects.social-package-revoke');
            Route::get('/packages/{package}/export', [SocialPublishingController::class, 'export'])->whereUuid('package')->name('video-projects.social-package-export');
            Route::post('/packages/{package}/manual', [SocialPublishingController::class, 'reportManual'])->whereUuid('package')->name('video-projects.social-manual');
            Route::post('/packages/{package}/publish', [SocialPublishingController::class, 'publish'])->whereUuid('package')->name('video-projects.social-publish');
            Route::post('/publications/{publication}/follow', [SocialPublishingController::class, 'followPublication'])->whereUuid('publication')->name('video-projects.social-publication-follow');
            Route::post('/publications/{publication}/cancel', [SocialPublishingController::class, 'cancelPublication'])->whereUuid('publication')->name('video-projects.social-publication-cancel');
            Route::post('/publications/{publication}/resolve', [SocialPublishingController::class, 'resolvePublication'])->whereUuid('publication')->name('video-projects.social-publication-resolve');
            Route::post('/publications/{publication}/reschedule', [SocialPublishingController::class, 'reschedulePublication'])->whereUuid('publication')->name('video-projects.social-publication-reschedule');
            Route::post('/publications/{publication}/metrics', [SocialPublishingController::class, 'syncMetrics'])->whereUuid('publication')->name('video-projects.social-publication-metrics');
        });
        // Noi nen tang tra nguoi dung ve sau khi cap quyen. Dia chi co dinh vi ung dung OAuth doi dung tung ky tu; du an quay ve nam trong phien.
        Route::get('/video-social/oauth/{platform}/callback', [SocialPublishingController::class, 'oauthCallback'])->whereIn('platform', ['youtube', 'facebook'])->name('video-social.oauth-callback');

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
