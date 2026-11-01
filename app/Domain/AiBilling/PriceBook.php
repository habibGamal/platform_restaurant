<?php

declare(strict_types=1);

namespace App\Domain\AiBilling;

use App\Models\AiModelPrice;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class PriceBook
{
    /**
     * Look up the active price for a given provider and model at a specific time.
     * Returns the latest row where effective_from <= at.
     */
    public function priceFor(string $provider, string $model, ?CarbonInterface $at = null): ?AiModelPrice
    {
        $atDate = ($at ?? Carbon::now())->toDateString();

        return AiModelPrice::query()
            ->where('provider', $provider)
            ->where('model', $model)
            ->where('effective_from', '<=', $atDate)
            ->orderByDesc('effective_from')
            ->first();
    }
}
