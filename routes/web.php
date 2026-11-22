<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

Route::get('/docs/{file?}', function (?string $file = null) {
    $file = $file ? ltrim($file, '/') : 'index.html';

    // Any request for a sidebar file returns the root docs/_sidebar.md
    if (str_ends_with($file, '_sidebar.md')) {
        return response()->file(base_path('docs/_sidebar.md'), [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }

    if ($file === 'CHANGELOG.md') {
        $path = base_path('CHANGELOG.md');
    } else {
        $path = base_path('docs/'.$file);
    }

    if (! file_exists($path) || is_dir($path)) {
        if (file_exists($path.'/README.md')) {
            $path = $path.'/README.md';
        } elseif (file_exists($path.'/index.html')) {
            $path = $path.'/index.html';
        } elseif (str_ends_with($file, '.md')) {
            abort(404);
        } else {
            $path = base_path('docs/index.html');
        }
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = match ($extension) {
        'html' => 'text/html; charset=UTF-8',
        'md' => 'text/markdown; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        default => 'text/plain; charset=UTF-8',
    };

    return response()->file($path, [
        'Content-Type' => $mime,
    ]);
})->where('file', '.*')->name('docs');

require __DIR__.'/settings.php';
require __DIR__.'/assistant.php';
