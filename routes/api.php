<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PdpController;
use App\Http\Controllers\PdpSkillController;

Route::middleware(['auth:sanctum'])->group(function () {
    // PDPs
    Route::get('/pdps', [PdpController::class, 'index']);
    Route::get('/pdps/shared', [PdpController::class, 'shared']);
    Route::post('/pdps', [PdpController::class, 'store']);
    Route::post('/pdps/{pdp}/assign-curator', [PdpController::class, 'assignCurator']);
    Route::delete('/pdps/{pdp}/curators/{user}', [PdpController::class, 'removeCurator']);
    Route::get('/pdps/{pdp}', [PdpController::class, 'show']);
    Route::get('/pdps/{pdp}/export', [PdpController::class, 'export']);
    Route::post('/pdps/import', [PdpController::class, 'import']);
    Route::put('/pdps/{pdp}', [PdpController::class, 'update']);
    Route::delete('/pdps/{pdp}', [PdpController::class, 'destroy']);

    // PDP Skills
    Route::get('/pdps/{pdp}/skills', [PdpSkillController::class, 'index']);
    Route::post('/pdps/{pdp}/skills', [PdpSkillController::class, 'store']);
    Route::put('/pdps/{pdp}/skills/{skill}', [PdpSkillController::class, 'update']);
    Route::delete('/pdps/{pdp}/skills/{skill}', [PdpSkillController::class, 'destroy']);
});
