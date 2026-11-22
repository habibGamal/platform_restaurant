<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Assistant\AiAccess;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Let a request through only for a user the assistant is enabled for (organization on a plan
 * with AI, AI turned on, permission granted): the same gate as the in-app assistant.
 */
final class EnsureAssistantAccess
{
    public function __construct(private readonly AiAccess $access = new AiAccess) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->access->allows($user)) {
            $code = $user instanceof User ? ($this->access->denialReason($user) ?? 'forbidden') : 'forbidden';

            return response()->json([
                'error' => 'forbidden',
                'error_description' => $user instanceof User ? $this->access->messageAr($code) : 'Unauthenticated.',
            ], 403);
        }

        return $next($request);
    }
}
