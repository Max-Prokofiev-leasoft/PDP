<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PdpController;
use App\Http\Controllers\PdpSkillController;

Route::middleware(['auth:sanctum'])->group(function () {
    // PDPs
    Route::get('/pdps', [PdpController::class, 'index']);
    Route::post('/pdps', [PdpController::class, 'store']);
    Route::get('/pdps/{pdp}', [PdpController::class, 'show']);
    Route::put('/pdps/{pdp}', [PdpController::class, 'update']);
    Route::delete('/pdps/{pdp}', [PdpController::class, 'destroy']);

    // PDP Skills
    Route::get('/pdps/{pdp}/skills', [PdpSkillController::class, 'index']);
    Route::post('/pdps/{pdp}/skills', [PdpSkillController::class, 'store']);
    Route::put('/pdps/{pdp}/skills/{skill}', [PdpSkillController::class, 'update']);
    Route::delete('/pdps/{pdp}/skills/{skill}', [PdpSkillController::class, 'destroy']);
});
