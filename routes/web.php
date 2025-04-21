<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\GetLinkController;
use App\Http\Controllers\GetTagController;
use App\Http\Controllers\InforDomainsController;
use App\Http\Controllers\ModalConfirmController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\TagController;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

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
        // Route::match(["get", "post"], "/addpost", [PostController::class, "addPost"])->name("post.addpost");
        Route::match(["get", "post"], "/update/{id}", [PostController::class, "update"])->name("post.update");
        Route::match(["get", "post"], "/delete", [PostController::class, "delete"])->name("post.delete");
        Route::match(["get", "post"], "/detail/{id}", [PostController::class, "detail"])->name("post.detail");
    });

    // infor domain
    Route::group(["prefix" => "domain"], function () {
        Route::get("/", [InforDomainsController::class, "index"])->name("domain.index");
        Route::match(["get", "post"], "/add", [InforDomainsController::class, "add"])->name("domain.add");
        Route::match(["get", "post"], "/update/{id}", [InforDomainsController::class, "update"])->name("domain.update");
        Route::match(["get", "post"], "/delete", [InforDomainsController::class, "delete"])->name("domain.delete");
        Route::match(["get", "post"], "/detail/{id}", [InforDomainsController::class, "detail"])->name("domain.detail");
    });

    Route::post("/getlink", [GetLinkController::class, "getLink"]);
    Route::get("/get-tags", [GetTagController::class, "getTags"]);
    Route::get("/modal-confirm", [ModalConfirmController::class, "modalConfirm"])->name("modal.confirm");
});

Route::group(['prefix' => 'laravel-filemanager', 'middleware' => ['web', 'auth']], function () {
    \UniSharp\LaravelFilemanager\Lfm::routes();
});
