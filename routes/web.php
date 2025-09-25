<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\PdpController;
use App\Http\Controllers\PdpSkillController;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// PDP list page
Route::get('pdps', function () {
    return Inertia::render('pdps/Index');
})->middleware(['auth', 'verified'])->name('pdps.index');

// JSON endpoints for PDPs and Skills (session-authenticated + CSRF)
Route::middleware(['auth', 'verified'])->group(function () {
    // PDPs
    Route::get('/pdps.json', [PdpController::class, 'index']);
    Route::post('/pdps.json', [PdpController::class, 'store']);
    Route::get('/pdps/{pdp}.json', [PdpController::class, 'show']);
    Route::put('/pdps/{pdp}.json', [PdpController::class, 'update']);
    Route::delete('/pdps/{pdp}.json', [PdpController::class, 'destroy']);

    // PDP Skills
    Route::get('/pdps/{pdp}/skills.json', [PdpSkillController::class, 'index']);
    Route::post('/pdps/{pdp}/skills.json', [PdpSkillController::class, 'store']);
    Route::put('/pdps/{pdp}/skills/{skill}.json', [PdpSkillController::class, 'update']);
    Route::patch('/pdps/{pdp}/skills/{skill}/criteria/{index}.json', [PdpSkillController::class, 'updateCriterionComment'])->whereNumber('index'); // legacy single comment support
    Route::get('/pdps/{pdp}/skills/{skill}/criteria/{index}/progress.json', [PdpSkillController::class, 'listProgress'])->whereNumber('index');
    Route::post('/pdps/{pdp}/skills/{skill}/criteria/{index}/progress.json', [PdpSkillController::class, 'addProgress'])->whereNumber('index');
    Route::post('/pdps/{pdp}/skills/{skill}/criteria/{index}/progress/{entry}/approve.json', [PdpSkillController::class, 'approveProgress'])->whereNumber('index')->whereNumber('entry');
    Route::delete('/pdps/{pdp}/skills/{skill}/criteria/{index}/progress/{entry}.json', [PdpSkillController::class, 'deleteProgress'])->whereNumber('index')->whereNumber('entry');

    // Annex JSON (document-like view)
    Route::get('/pdps/{pdp}/annex.json', [PdpSkillController::class, 'annex']);

    Route::delete('/pdps/{pdp}/skills/{skill}.json', [PdpSkillController::class, 'destroy']);
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
