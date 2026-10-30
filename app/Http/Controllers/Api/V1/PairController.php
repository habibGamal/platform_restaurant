<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\PairingCodeService;
use App\Enums\BranchStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PairController extends Controller
{
    public function pair(Request $request): JsonResponse
    {
        $code = (string) $request->input('code', '');
        $branch = PairingCodeService::redeem($code);

        if (! $branch) {
            return response()->json([
                'message' => 'رمز الربط غير صالح أو منتهي',
            ], 422);
        }

        $branch->status = BranchStatus::ACTIVE->value;
        if ($request->filled('pos_version')) {
            $branch->pos_version = (string) $request->input('pos_version');
        }
        $branch->save();

        $token = $branch->issueIngestToken();

        return response()->json([
            'branch_uuid' => $branch->uuid,
            'token' => $token,
            'branch_secret' => $branch->branch_secret,
            'timezone' => $branch->timezone,
        ], 200);
    }

    public function rotate(Request $request): JsonResponse
    {
        $branch = $request->user('sanctum');

        if (! $branch instanceof Branch) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token = $branch->issueIngestToken();

        return response()->json([
            'token' => $token,
        ], 200);
    }
}
