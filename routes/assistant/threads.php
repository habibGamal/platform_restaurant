<?php

declare(strict_types=1);

use App\Http\Controllers\Assistant\ThreadController;
use Illuminate\Support\Facades\Route;

Route::prefix('assistant/api')
    ->middleware(['auth', 'verified', 'can:use-assistant'])
    ->as('assistant.')
    ->group(function (): void {
        Route::get('threads', [ThreadController::class, 'index'])->name('threads.index');
        Route::post('threads', [ThreadController::class, 'store'])->name('threads.store');
        Route::get('threads/{id}', [ThreadController::class, 'show'])->name('threads.show');
        Route::patch('threads/{id}', [ThreadController::class, 'update'])->name('threads.update');
        Route::delete('threads/{id}', [ThreadController::class, 'destroy'])->name('threads.destroy');
        Route::post('threads/{id}/title', [ThreadController::class, 'title'])->name('threads.title');
        Route::get('threads/{id}/messages', [ThreadController::class, 'messages'])->name('threads.messages');
    });
