<?php

use App\Http\Controllers\PrankController;
use Illuminate\Support\Facades\Route;

// PRANK: presentation joke only. Never merge this branch. The controller answers 404 unless PRANK_ENABLED=true.
Route::middleware(['auth', 'verified', 'permission:dispensary.view.branch'])
    ->prefix('prank/{caseId}')
    ->controller(PrankController::class)->group(function () {
        Route::get('pharmamax', 'pharmamax')->whereUuid('caseId')->name('prank.pharmamax');
        Route::get('error', 'oops')->whereUuid('caseId')->name('prank.error');
        Route::get('reveal', 'reveal')->whereUuid('caseId')->name('prank.reveal');
    });
