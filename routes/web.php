<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\PdpController;
use App\Http\Controllers\PdpSkillController;
use App\Http\Controllers\PdpProgressController;
use App\Http\Controllers\UserProfessionalLevelController;

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

// PDP templates page
Route::get('pdps/templates', function () {
    return Inertia::render('pdps/Templates');
})->middleware(['auth', 'verified'])->name('pdps.templates');

// JSON endpoints for PDPs and Skills (session-authenticated + CSRF)
Route::middleware(['auth', 'verified'])->group(function () {
    // PDPs
    Route::get('/pdps.json', [PdpController::class, 'index']);
    Route::get('/pdps.shared.json', [PdpController::class, 'shared']);
    Route::get('/pdps/templates.json', [PdpController::class, 'templates']);
    Route::post('/pdps/templates.json', [PdpController::class, 'createTemplate']);
    Route::post('/pdps/templates/{key}/assign.json', [PdpController::class, 'assignTemplate']);
    Route::post('/pdps.json', [PdpController::class, 'store']);
    Route::post('/pdps/{pdp}/assign-curator.json', [PdpController::class, 'assignCurator']);
    Route::get('/pdps/{pdp}/curators.json', [PdpController::class, 'curators']);
    Route::delete('/pdps/{pdp}/curators/{user}.json', [PdpController::class, 'removeCurator']);
    Route::get('/pdps/{pdp}.json', [PdpController::class, 'show']);
    Route::get('/pdps/{pdp}/export.json', [PdpController::class, 'export']);
    Route::post('/pdps/{pdp}/transfer.json', [PdpController::class, 'transfer']);
    Route::post('/pdps/import.json', [PdpController::class, 'import']);
    Route::put('/pdps/{pdp}.json', [PdpController::class, 'update']);
    Route::delete('/pdps/{pdp}.json', [PdpController::class, 'destroy']);

    // Users search for assignment dropdown
    Route::get('/users.search.json', [PdpController::class, 'usersSearch']);

    // PDP Skills
    Route::get('/pdps/{pdp}/skills.json', [PdpSkillController::class, 'index']);
    Route::post('/pdps/{pdp}/skills.json', [PdpSkillController::class, 'store']);
    Route::put('/pdps/{pdp}/skills/{skill}.json', [PdpSkillController::class, 'update']);
    Route::patch('/pdps/{pdp}/skills/{skill}/criteria/{index}.json', [PdpSkillController::class, 'updateCriterionComment'])->whereNumber('index'); // legacy single comment support
    Route::patch('/pdps/{pdp}/skills/{skill}/criteria/{index}/done.json', [PdpSkillController::class, 'updateCriterionDone'])->whereNumber('index');
    Route::get('/pdps/{pdp}/skills/{skill}/criteria/{index}/progress.json', [PdpSkillController::class, 'listProgress'])->whereNumber('index');
    Route::post('/pdps/{pdp}/skills/{skill}/criteria/{index}/progress.json', [PdpSkillController::class, 'addProgress'])->whereNumber('index');
    Route::post('/pdps/{pdp}/skills/{skill}/criteria/{index}/progress/{entry}/approve.json', [PdpSkillController::class, 'approveProgress'])->whereNumber('index')->whereNumber('entry');
    Route::delete('/pdps/{pdp}/skills/{skill}/criteria/{index}/progress/{entry}.json', [PdpSkillController::class, 'deleteProgress'])->whereNumber('index')->whereNumber('entry');

    // Annex JSON (document-like view)
    Route::get('/pdps/{pdp}/annex.json', [PdpSkillController::class, 'annex']);

    // Dashboard: pending approvals for current curator
    Route::get('/dashboard/pending-approvals.json', [PdpSkillController::class, 'pendingApprovals']);
    // Dashboard: PDP summary (KPI tiles + per-skill breakdown)
    Route::get('/dashboard/pdps/{pdp}/summary.json', [PdpSkillController::class, 'summary']);
    // Dashboard: My PDPs snapshot overview
    Route::get('/dashboard/pdps/overview.json', [PdpController::class, 'overview']);

    // PDP progress by closed skills (Done)
    Route::get('/pdps/{pdp}/progress.json', [PdpProgressController::class, 'show']);

    // User professional level (global, based on closed skills across all PDPs)
    Route::get('/profile/pro-level.json', [UserProfessionalLevelController::class, 'show']);

    Route::delete('/pdps/{pdp}/skills/{skill}.json', [PdpSkillController::class, 'destroy']);
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
