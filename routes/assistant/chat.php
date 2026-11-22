<?php

declare(strict_types=1);

use App\Http\Controllers\Assistant\ChatController;
use App\Http\Controllers\Assistant\RunController;
use Illuminate\Support\Facades\Route;

Route::prefix('assistant/api')
    ->middleware(['auth', 'verified', 'can:use-assistant'])
    ->as('assistant.')
    ->group(function (): void {
        Route::post('chat', ChatController::class)->name('chat');
        Route::get('runs/{runId}/stream', [RunController::class, 'stream'])->name('runs.stream');
        Route::post('runs/{runId}/cancel', [RunController::class, 'cancel'])->name('runs.cancel');
    });
