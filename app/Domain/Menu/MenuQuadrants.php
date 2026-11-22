<?php

declare(strict_types=1);

namespace App\Domain\Menu;

/**
 * Classic menu-engineering quadrants (popularity × unit contribution margin) over one group of
 * comparable items. Callers decide the grouping (a role, a segment) and the eligibility rules.
 *
 * @phpstan-type InputRow array{key: string, name: string, role: string, qty: float, net_sales: float, cogs: float}
 * @phpstan-type ItemRow array{key: string, name: string, role: string, qty: float, net_sales: float, unit_margin: float, margin_pct: float|null, mix_share: float, popularity_ratio: float, margin_vs_avg: float|null}
 * @phpstan-type Quadrants array{hidden_gems: list<ItemRow>, stars: list<ItemRow>, workhorses: list<ItemRow>, weak: list<ItemRow>}
 */
final class MenuQuadrants
{
    /** An item is popular when its mix share reaches this fraction of the fair share 1/N. */
    public const POPULARITY_THRESHOLD = 0.70;

    /**
     * Every row must have qty > 0.
     *
     * @param  non-empty-list<InputRow>  $rows
     * @return array{0: Quadrants, 1: float} the quadrants and the group's margin floor (margin / net sales)
     */
    public static function classify(array $rows): array
    {
        $n = count($rows);
        $totalQty = array_sum(array_column($rows, 'qty'));
        $totalNet = array_sum(array_column($rows, 'net_sales'));
        $totalMargin = $totalNet - array_sum(array_column($rows, 'cogs'));
        $avgCm = $totalMargin / $totalQty;

        $quadrants = ['hidden_gems' => [], 'stars' => [], 'workhorses' => [], 'weak' => []];

        foreach ($rows as $row) {
            $margin = $row['net_sales'] - $row['cogs'];
            $unitCm = $margin / $row['qty'];
            $mixShare = $row['qty'] / $totalQty;
            $popularityRatio = $mixShare * $n;

            $popular = $popularityRatio >= self::POPULARITY_THRESHOLD;
            $profitable = $unitCm >= $avgCm;

            $quadrant = match (true) {
                $profitable && ! $popular => 'hidden_gems',
                $profitable => 'stars',
                $popular => 'workhorses',
                default => 'weak',
            };

            $quadrants[$quadrant][] = [
                'key' => $row['key'],
                'name' => $row['name'],
                'role' => $row['role'],
                'qty' => round($row['qty'], 3),
                'net_sales' => round($row['net_sales'], 2),
                'unit_margin' => round($unitCm, 2),
                'margin_pct' => $row['net_sales'] > 0 ? round($margin / $row['net_sales'], 4) : null,
                'mix_share' => round($mixShare, 4),
                'popularity_ratio' => round($popularityRatio, 4),
                'margin_vs_avg' => $avgCm > 0 ? round($unitCm / $avgCm, 4) : null,
            ];
        }

        $byMargin = fn (array $a, array $b): int => [$b['margin_vs_avg'], $b['qty']] <=> [$a['margin_vs_avg'], $a['qty']];
        $byPopularity = fn (array $a, array $b): int => [$b['popularity_ratio'], $b['margin_vs_avg']] <=> [$a['popularity_ratio'], $a['margin_vs_avg']];
        usort($quadrants['hidden_gems'], $byMargin);
        usort($quadrants['stars'], $byMargin);
        usort($quadrants['workhorses'], $byPopularity);
        usort($quadrants['weak'], $byMargin);

        $floor = $totalNet > 0 ? $totalMargin / $totalNet : 0.0;

        return [$quadrants, $floor];
    }
}
