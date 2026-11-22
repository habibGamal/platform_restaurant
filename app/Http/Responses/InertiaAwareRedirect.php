<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * After an Inertia login form, a plain redirect to a page that is not an Inertia page (the OAuth
 * consent screen an MCP client sent the owner to) would render inside Inertia's error dialog.
 * Send a full-page visit instead.
 */
final class InertiaAwareRedirect
{
    private const NON_INERTIA_PATHS = ['/oauth/'];

    public static function adapt(Request $request, mixed $response): mixed
    {
        if (! $request->header('X-Inertia') || ! $response instanceof RedirectResponse) {
            return $response;
        }

        $path = (string) parse_url($response->getTargetUrl(), PHP_URL_PATH);
        foreach (self::NON_INERTIA_PATHS as $prefix) {
            if (str_starts_with($path, $prefix)) {
                /** @var Response */
                return Inertia::location($response->getTargetUrl());
            }
        }

        return $response;
    }
}
