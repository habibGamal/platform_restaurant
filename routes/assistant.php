<?php

declare(strict_types=1);

use App\Http\Controllers\Assistant\AssistantPageController;
use Illuminate\Support\Facades\Route;

Route::get('/assistant', AssistantPageController::class)
    ->middleware(['auth', 'verified', 'can:use-assistant'])
    ->name('assistant');

foreach (glob(__DIR__.'/assistant/*.php') ?: [] as $file) {
    require $file;
}
