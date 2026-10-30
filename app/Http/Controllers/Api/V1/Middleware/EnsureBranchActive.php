<?php

namespace App\Http\Controllers\Api\V1\Middleware;

use App\Enums\BranchStatus;
use App\Models\Branch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBranchActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $branch = $request->user('sanctum');

        if ($branch instanceof Branch) {
            $status = $branch->getAttribute('status');
            if ($status === BranchStatus::SUSPENDED || $status === BranchStatus::SUSPENDED->value) {
                return response()->json([
                    'message' => 'الفرع معلق',
                ], 403);
            }
        }

        return $next($request);
    }
}
