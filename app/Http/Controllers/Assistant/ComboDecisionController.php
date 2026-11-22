<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Domain\Menu\Intelligence\ComboDecisions;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ComboDecisionController extends Controller
{
    /**
     * Apply owner decision (approved / rejected / candidate) to all period rows of a combo signature.
     */
    public function __invoke(Request $request, string $signature): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null || ! $user->can('ai.manage') || $user->organization_id === null) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(ComboDecisions::VALID_STATUSES)],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $updated = ComboDecisions::apply(
            (int) $user->organization_id,
            $signature,
            (string) $validated['status'],
            isset($validated['note']) && is_string($validated['note']) ? $validated['note'] : null,
        );

        if ($updated === 0) {
            abort(404, 'الكومبو غير موجود.');
        }

        return response()->json([
            'status' => $validated['status'],
            'updated' => $updated,
        ]);
    }
}
