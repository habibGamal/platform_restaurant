<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeartbeatController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pos_version' => ['required', 'string'],
            'pending_count' => ['nullable', 'integer'],
            'oldest_pending_at' => ['nullable', 'date'],
            'failed_count' => ['nullable', 'integer'],
            'last_success_at' => ['nullable', 'date'],
            'server_time' => ['nullable', 'date'],
            'business_date_open' => ['nullable', 'date'],
        ]);

        $branch = $request->user('sanctum');
        if (! $branch instanceof Branch) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $branch->pos_version = $validated['pos_version'];

        if (array_key_exists('pending_count', $validated) && $validated['pending_count'] !== null) {
            $branch->pending_count = max(0, (int) $validated['pending_count']);
        }

        if (array_key_exists('failed_count', $validated) && $validated['failed_count'] !== null) {
            $branch->failed_count = max(0, (int) $validated['failed_count']);
        }

        if (array_key_exists('oldest_pending_at', $validated)) {
            $branch->oldest_pending_at = $validated['oldest_pending_at'];
        }

        $branch->last_heartbeat_at = now();
        $branch->last_seen_at = now();
        $branch->save();

        $commands = BranchCommand::where('branch_id', $branch->id)
            ->whereNull('delivered_at')
            ->orderBy('id')
            ->get();

        $commandList = $commands->map(function (BranchCommand $cmd): array {
            $rawPayload = $cmd->getAttribute('payload');
            $payload = is_array($rawPayload) ? $rawPayload : (is_string($rawPayload) ? json_decode($rawPayload, true) : []);
            $payload = is_array($payload) ? $payload : [];

            return array_merge(['type' => $cmd->type], $payload);
        })->values()->all();

        if ($commands->isNotEmpty()) {
            BranchCommand::whereIn('id', $commands->pluck('id'))->update(['delivered_at' => now()]);
        }

        return response()->json([
            'commands' => $commandList,
            'min_supported_schema' => 1,
        ], 200);
    }
}
