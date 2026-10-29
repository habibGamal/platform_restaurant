<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

final readonly class KpiValues
{
    public function __construct(
        public int $orders_count = 0,
        public int $cancelled_count = 0,
        public float $gross_sales = 0.0,
        public float $discounts = 0.0,
        public float $tax = 0.0,
        public float $service = 0.0,
        public float $net_sales = 0.0,
        public float $returns_amount = 0.0,
        public float $net_sales_after_returns = 0.0,
        public float $cogs = 0.0,
        public float $returned_cogs_recovered = 0.0,
        public float $gross_profit = 0.0,
        public float $expenses_total = 0.0,
        public float $labor_cost = 0.0,
        public float $rent_cost = 0.0,
        public float $other_expenses = 0.0,
        public float $waste_cost = 0.0,
        public float $inventory_variance_cost = 0.0,
        public float $purchases_total = 0.0,
        public float $purchase_returns_total = 0.0,
        public float $net_profit = 0.0,
        public int $shifts_count = 0,
        public float $cash_deficit_total = 0.0,
        public int $days_with_data = 0,
    ) {}

    /**
     * K07: Average ticket = net_sales / orders.
     */
    public function avgTicket(): ?float
    {
        if ($this->orders_count === 0) {
            return null;
        }

        return $this->net_sales / $this->orders_count;
    }

    /**
     * K10: Gross margin = gross_profit / net_sales_after_returns.
     */
    public function grossMargin(): ?float
    {
        if ($this->net_sales_after_returns == 0.0) {
            return null;
        }

        return $this->gross_profit / $this->net_sales_after_returns;
    }

    /**
     * K15: Net margin = net_profit / net_sales_after_returns.
     */
    public function netMargin(): ?float
    {
        if ($this->net_sales_after_returns == 0.0) {
            return null;
        }

        return $this->net_profit / $this->net_sales_after_returns;
    }

    /**
     * K16: Food cost % = (cogs - returned_cogs_recovered) / net_sales_after_returns.
     */
    public function foodCostPct(): ?float
    {
        if ($this->net_sales_after_returns == 0.0) {
            return null;
        }

        return ($this->cogs - $this->returned_cogs_recovered) / $this->net_sales_after_returns;
    }

    /**
     * K17: Waste % = waste_cost / net_sales_after_returns.
     */
    public function wastePct(): ?float
    {
        if ($this->net_sales_after_returns == 0.0) {
            return null;
        }

        return $this->waste_cost / $this->net_sales_after_returns;
    }

    /**
     * K17 (secondary): Waste share of material usage = waste_cost / ((cogs - returned_cogs_recovered) + waste_cost).
     */
    public function wasteShareOfUsage(): ?float
    {
        $materialUsage = ($this->cogs - $this->returned_cogs_recovered) + $this->waste_cost;
        if ($materialUsage == 0.0) {
            return null;
        }

        return $this->waste_cost / $materialUsage;
    }

    /**
     * K18: Labor % = allocated_labor / net_sales_after_returns.
     */
    public function laborPct(float $allocatedLabor): ?float
    {
        if ($this->net_sales_after_returns == 0.0) {
            return null;
        }

        return $allocatedLabor / $this->net_sales_after_returns;
    }

    /**
     * K19: Prime cost % = ((cogs - returned_cogs_recovered) + allocated_labor) / net_sales_after_returns.
     */
    public function primeCostPct(float $allocatedLabor): ?float
    {
        if ($this->net_sales_after_returns == 0.0) {
            return null;
        }

        return (($this->cogs - $this->returned_cogs_recovered) + $allocatedLabor) / $this->net_sales_after_returns;
    }

    /**
     * K20: Net purchases = purchases_total - purchase_returns_total.
     */
    public function netPurchases(): float
    {
        return $this->purchases_total - $this->purchase_returns_total;
    }

    /**
     * K21: Cancel rate = cancelled_count / (orders_count + cancelled_count).
     */
    public function cancelRate(): ?float
    {
        $total = $this->orders_count + $this->cancelled_count;
        if ($total === 0) {
            return null;
        }

        return $this->cancelled_count / $total;
    }

    /**
     * K22: Return rate = returns_amount / net_sales.
     */
    public function returnRate(): ?float
    {
        if ($this->net_sales == 0.0) {
            return null;
        }

        return $this->returns_amount / $this->net_sales;
    }

    public static function zero(): self
    {
        return new self;
    }

    public function plus(self $other): self
    {
        return new self(
            orders_count: $this->orders_count + $other->orders_count,
            cancelled_count: $this->cancelled_count + $other->cancelled_count,
            gross_sales: $this->gross_sales + $other->gross_sales,
            discounts: $this->discounts + $other->discounts,
            tax: $this->tax + $other->tax,
            service: $this->service + $other->service,
            net_sales: $this->net_sales + $other->net_sales,
            returns_amount: $this->returns_amount + $other->returns_amount,
            net_sales_after_returns: $this->net_sales_after_returns + $other->net_sales_after_returns,
            cogs: $this->cogs + $other->cogs,
            returned_cogs_recovered: $this->returned_cogs_recovered + $other->returned_cogs_recovered,
            gross_profit: $this->gross_profit + $other->gross_profit,
            expenses_total: $this->expenses_total + $other->expenses_total,
            labor_cost: $this->labor_cost + $other->labor_cost,
            rent_cost: $this->rent_cost + $other->rent_cost,
            other_expenses: $this->other_expenses + $other->other_expenses,
            waste_cost: $this->waste_cost + $other->waste_cost,
            inventory_variance_cost: $this->inventory_variance_cost + $other->inventory_variance_cost,
            purchases_total: $this->purchases_total + $other->purchases_total,
            purchase_returns_total: $this->purchase_returns_total + $other->purchase_returns_total,
            net_profit: $this->net_profit + $other->net_profit,
            shifts_count: $this->shifts_count + $other->shifts_count,
            cash_deficit_total: $this->cash_deficit_total + $other->cash_deficit_total,
            days_with_data: $this->days_with_data + $other->days_with_data,
        );
    }

    /**
     * @param  object|array<string, mixed>  $row
     */
    public static function fromRow(object|array $row): self
    {
        $get = function (string $key, mixed $default = 0.0) use ($row): mixed {
            if (is_array($row)) {
                return $row[$key] ?? $default;
            }

            return $row->{$key} ?? $default;
        };

        $hasKey = function (string $key) use ($row): bool {
            if (is_array($row)) {
                return array_key_exists($key, $row);
            }

            return property_exists($row, $key) || isset($row->{$key});
        };

        $daysWithData = 0;
        if ($hasKey('days_with_data')) {
            $daysWithData = (int) $get('days_with_data', 0);
        } elseif ($hasKey('business_date')) {
            $daysWithData = 1;
        }

        return new self(
            orders_count: (int) $get('orders_count', 0),
            cancelled_count: (int) $get('cancelled_count', 0),
            gross_sales: (float) $get('gross_sales', 0.0),
            discounts: (float) $get('discounts', 0.0),
            tax: (float) $get('tax', 0.0),
            service: (float) $get('service', 0.0),
            net_sales: (float) $get('net_sales', 0.0),
            returns_amount: (float) $get('returns_amount', 0.0),
            net_sales_after_returns: (float) $get('net_sales_after_returns', 0.0),
            cogs: (float) $get('cogs', 0.0),
            returned_cogs_recovered: (float) $get('returned_cogs_recovered', 0.0),
            gross_profit: (float) $get('gross_profit', 0.0),
            expenses_total: (float) $get('expenses_total', 0.0),
            labor_cost: (float) $get('labor_cost', 0.0),
            rent_cost: (float) $get('rent_cost', 0.0),
            other_expenses: (float) $get('other_expenses', 0.0),
            waste_cost: (float) $get('waste_cost', 0.0),
            inventory_variance_cost: (float) $get('inventory_variance_cost', 0.0),
            purchases_total: (float) $get('purchases_total', 0.0),
            purchase_returns_total: (float) $get('purchase_returns_total', 0.0),
            net_profit: (float) $get('net_profit', 0.0),
            shifts_count: (int) $get('shifts_count', 0),
            cash_deficit_total: (float) $get('cash_deficit_total', 0.0),
            days_with_data: $daysWithData,
        );
    }
}
