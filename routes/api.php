<?php

use App\Http\Controllers\Api\V1\HeartbeatController;
use App\Http\Controllers\Api\V1\IngestBatchController;
use App\Http\Controllers\Api\V1\Middleware\EnsureBranchActive;
use App\Http\Controllers\Api\V1\PairController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('/pair', [PairController::class, 'pair'])
        ->middleware('throttle:ingest-pair')
        ->name('pair');

    Route::middleware([
        'auth:sanctum',
        'ability:ingest',
        EnsureBranchActive::class,
    ])->group(function () {
        Route::post('/pair/rotate', [PairController::class, 'rotate'])->name('pair.rotate');
        Route::post('/ingest/batches', [IngestBatchController::class, 'store'])
            ->middleware('throttle:ingest-batches')
            ->name('ingest.batches');
        Route::post('/heartbeat', [HeartbeatController::class, 'store'])->name('heartbeat');
    });
});
