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
// Reset drops and reseeds every table. Unauthenticated by design so a judge
// can recover the demo, but without a limit it can be held down, and every
// visitor then sees a site mid-rebuild.
Route::post('/api/demo/reset', [DemoController::class, 'reset'])->middleware('throttle:1,5');

Route::prefix('api')->group(function () {
    Route::get('site', [SiteController::class, 'show']);
    Route::patch('site/identity', [SiteController::class, 'updateIdentity']);
    Route::patch('site/footer', [SiteController::class, 'updateFooter']);
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
    ->middleware('throttle:5,10')
    ->name('submissions.store');

Route::prefix('api')->group(function () {
    Route::get('theme', [App\Http\Controllers\ThemeController::class, 'show']);
    Route::patch('theme', [App\Http\Controllers\ThemeController::class, 'update']);
    Route::post('theme/revert', [App\Http\Controllers\ThemeController::class, 'revert']);
    Route::get('media', [App\Http\Controllers\MediaController::class, 'index']);
    // Each call can spend real money at the image API.
    Route::post('media/generate', [App\Http\Controllers\MediaController::class, 'generate'])->middleware('throttle:10,1');
    Route::post('media/svg', [App\Http\Controllers\MediaController::class, 'svg']);
    Route::post('media/{asset}/regenerate', [App\Http\Controllers\MediaController::class, 'regenerate'])->middleware('throttle:10,1');
    Route::patch('media/{asset}', [App\Http\Controllers\MediaController::class, 'updateAlt']);
    Route::patch('pages/{page}/seo', [App\Http\Controllers\PublishController::class, 'seo']);
    Route::get('pages/{page}/seo/check', [App\Http\Controllers\PublishController::class, 'seoCheck']);
    Route::get('pages/{page}/links/check', [App\Http\Controllers\PublishController::class, 'linksCheck']);
    Route::post('pages/{page}/links', [App\Http\Controllers\PublishController::class, 'addLink']);
    Route::get('pages/{page}/diff', [App\Http\Controllers\PublishController::class, 'diff']);
    Route::post('pages/{page}/publish', [App\Http\Controllers\PublishController::class, 'publish']);
    Route::get('pages/{page}/revisions', [App\Http\Controllers\PublishController::class, 'revisions']);
    Route::post('pages/{page}/revert', [App\Http\Controllers\PublishController::class, 'revert']);
    Route::get('pages/{page}/submissions', [App\Http\Controllers\PublishController::class, 'submissions']);
});

Route::prefix('api')->group(function () {
    Route::get('presets', [App\Http\Controllers\PresetController::class, 'index']);
    Route::post('presets/apply', [App\Http\Controllers\PresetController::class, 'apply']);
});
