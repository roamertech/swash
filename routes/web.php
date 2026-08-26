<?php

use App\Http\Controllers\BlockController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\EditorController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\SiteController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('/p/{page:slug}', [PageController::class, 'show'])->name('page.show');
Route::get('/editor', [EditorController::class, 'index'])->name('editor');
Route::get('/health', [HealthController::class, 'check']);
Route::post('/api/demo/reset', [DemoController::class, 'reset']);

Route::prefix('api')->group(function () {
    Route::get('site', [SiteController::class, 'show']);
    Route::patch('site/nav', [SiteController::class, 'updateNav']);
    Route::get('pages', [SiteController::class, 'listPages']);
    Route::post('pages', [SiteController::class, 'createPage']);
    Route::get('pages/{page}', [SiteController::class, 'showPage']);
    Route::patch('pages/{page}', [SiteController::class, 'updatePage']);
    Route::delete('pages/{page}', [SiteController::class, 'deletePage']);
    Route::post('pages/reorder', [SiteController::class, 'reorderPages']);
    Route::get('pages/{page}/outline', [BlockController::class, 'outline']);
    Route::post('pages/{page}/blocks', [BlockController::class, 'store']);
    Route::get('blocks/{block}', [BlockController::class, 'show']);
    Route::patch('blocks/{block}', [BlockController::class, 'update']);
    Route::delete('blocks/{block}', [BlockController::class, 'destroy']);
    Route::post('blocks/{block}/move', [BlockController::class, 'move']);
});

Route::post('/p/{page}/submissions', [App\Http\Controllers\PublishController::class, 'storeSubmission'])
    ->name('submissions.store');

Route::prefix('api')->group(function () {
    Route::get('theme', [App\Http\Controllers\ThemeController::class, 'show']);
    Route::patch('theme', [App\Http\Controllers\ThemeController::class, 'update']);
    Route::post('theme/revert', [App\Http\Controllers\ThemeController::class, 'revert']);
    Route::get('media', [App\Http\Controllers\MediaController::class, 'index']);
    Route::post('media/generate', [App\Http\Controllers\MediaController::class, 'generate']);
    Route::post('media/svg', [App\Http\Controllers\MediaController::class, 'svg']);
    Route::post('media/{asset}/regenerate', [App\Http\Controllers\MediaController::class, 'regenerate']);
    Route::patch('media/{asset}', [App\Http\Controllers\MediaController::class, 'updateAlt']);
    Route::patch('pages/{page}/seo', [App\Http\Controllers\PublishController::class, 'seo']);
    Route::get('pages/{page}/seo/check', [App\Http\Controllers\PublishController::class, 'seoCheck']);
    Route::get('pages/{page}/links/check', [App\Http\Controllers\PublishController::class, 'linksCheck']);
    Route::post('pages/{page}/links', [App\Http\Controllers\PublishController::class, 'addLink']);
    Route::get('pages/{page}/diff', [App\Http\Controllers\PublishController::class, 'diff']);
    Route::post('pages/{page}/publish', [App\Http\Controllers\PublishController::class, 'publish']);
    Route::post('pages/{page}/revert', [App\Http\Controllers\PublishController::class, 'revert']);
    Route::get('pages/{page}/submissions', [App\Http\Controllers\PublishController::class, 'submissions']);
});
