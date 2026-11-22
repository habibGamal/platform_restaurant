<?php

declare(strict_types=1);

use App\Http\Controllers\Assistant\ComboDecisionController;
use Illuminate\Support\Facades\Route;

Route::prefix('assistant/api')
    ->middleware(['auth', 'verified', 'can:use-assistant'])
    ->as('assistant.')
    ->group(function (): void {
        Route::post('combos/{signature}/decision', ComboDecisionController::class)->name('combos.decision');
    });
