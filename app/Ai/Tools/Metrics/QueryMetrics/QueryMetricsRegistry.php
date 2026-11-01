<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics\QueryMetrics;

final class QueryMetricsRegistry
{
    /** @var array<string, MetricDefinition> */
    private array $metrics = [];

    /** @var array<string, DimensionDefinition> */
    private array $dimensions = [];

    public function __construct()
    {
        $this->registerMetrics();
        $this->registerDimensions();
    }

    /**
     * @return array<string, MetricDefinition>
     */
    public function metrics(): array
    {
        return $this->metrics;
    }

    /**
     * @return array<string, DimensionDefinition>
     */
    public function dimensions(): array
    {
        return $this->dimensions;
    }

    public function getMetric(string $name): ?MetricDefinition
    {
        return $this->metrics[$name] ?? null;
    }

    public function getDimension(string $name): ?DimensionDefinition
    {
        return $this->dimensions[$name] ?? null;
    }

    public function hasMetric(string $name): bool
    {
        return isset($this->metrics[$name]);
    }

    public function hasDimension(string $name): bool
    {
        return isset($this->dimensions[$name]);
    }

    /**
     * @return list<string>
     */
    public function metricNames(): array
    {
        return array_keys($this->metrics);
    }

    /**
     * @return list<string>
     */
    public function dimensionNames(): array
    {
        return array_keys($this->dimensions);
    }

    private function registerMetrics(): void
    {
        // Orders (K01)
        $ordersImpl = [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(orders_count), 0)'),
            'agg_shift' => new MetricImplementation('agg_shift', 'COALESCE(SUM(orders_count), 0)'),
            'agg_branch_hourly' => new MetricImplementation('agg_branch_hourly', 'COALESCE(SUM(orders_count), 0)'),
        ];
        $this->addMetric('orders', $ordersImpl, 'K01', 'عدد الطلبات / Orders');
        $this->addMetric('orders_count', $ordersImpl, 'K01', 'عدد الطلبات / Orders');
        $this->addMetric('K01', $ordersImpl, 'K01', 'عدد الطلبات / Orders');

        // Gross Sales (K02)
        $grossSalesImpl = [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(gross_sales), 0)'),
            'agg_product_daily' => new MetricImplementation('agg_product_daily', 'COALESCE(SUM(gross_sales), 0)'),
        ];
        $this->addMetric('gross_sales', $grossSalesImpl, 'K02', 'إجمالي المبيعات قبل الخصم / Gross sales');
        $this->addMetric('K02', $grossSalesImpl, 'K02', 'إجمالي المبيعات قبل الخصم / Gross sales');

        // Discounts (K03)
        $discountsImpl = [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(discounts), 0)'),
            'agg_product_daily' => new MetricImplementation('agg_product_daily', 'COALESCE(SUM(item_discounts), 0)'),
        ];
        $this->addMetric('discounts', $discountsImpl, 'K03', 'الخصومات / Discounts');
        $this->addMetric('item_discounts', [
            'agg_product_daily' => new MetricImplementation('agg_product_daily', 'COALESCE(SUM(item_discounts), 0)'),
        ], 'K03', 'خصومات الأصناف / Item discounts');
        $this->addMetric('K03', $discountsImpl, 'K03', 'الخصومات / Discounts');

        // Net Sales (K04)
        $netSalesImpl = [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(net_sales), 0)'),
            'agg_product_daily' => new MetricImplementation('agg_product_daily', 'COALESCE(SUM(net_sales), 0)'),
            'agg_branch_hourly' => new MetricImplementation('agg_branch_hourly', 'COALESCE(SUM(net_sales), 0)'),
            'agg_shift' => new MetricImplementation('agg_shift', 'COALESCE(SUM(net_sales), 0)'),
        ];
        $this->addMetric('net_sales', $netSalesImpl, 'K04', 'صافي المبيعات / Net sales');
        $this->addMetric('K04', $netSalesImpl, 'K04', 'صافي المبيعات / Net sales');

        // Returns (K05)
        $returnsImpl = [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(returns_amount), 0)'),
        ];
        $this->addMetric('returns', $returnsImpl, 'K05', 'المرتجعات / Returns');
        $this->addMetric('returns_amount', $returnsImpl, 'K05', 'المرتجعات / Returns');
        $this->addMetric('K05', $returnsImpl, 'K05', 'المرتجعات / Returns');

        // Net Sales After Returns (K06) / Headline Sales
        $headlineSalesImpl = [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(net_sales_after_returns), 0)'),
            'agg_product_daily' => new MetricImplementation('agg_product_daily', 'COALESCE(SUM(net_sales), 0)'),
            'agg_branch_hourly' => new MetricImplementation('agg_branch_hourly', 'COALESCE(SUM(net_sales), 0)'),
            'agg_shift' => new MetricImplementation('agg_shift', 'COALESCE(SUM(net_sales), 0)'),
        ];
        $this->addMetric('sales', $headlineSalesImpl, 'K06', 'المبيعات / Sales');
        $this->addMetric('net_sales_after_returns', [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(net_sales_after_returns), 0)'),
        ], 'K06', 'المبيعات / Sales');
        $this->addMetric('K06', $headlineSalesImpl, 'K06', 'المبيعات / Sales');

        // Avg Ticket (K07) - Derived
        $avgTicketImpl = [
            'agg_branch_daily' => new MetricImplementation(
                'agg_branch_daily',
                'CASE WHEN SUM(orders_count) = 0 THEN NULL ELSE ROUND(SUM(net_sales) / SUM(orders_count), 2) END',
                isRatio: false,
                numeratorExpression: 'SUM(net_sales)',
                denominatorExpression: 'SUM(orders_count)',
            ),
            'agg_branch_hourly' => new MetricImplementation(
                'agg_branch_hourly',
                'CASE WHEN SUM(orders_count) = 0 THEN NULL ELSE ROUND(SUM(net_sales) / SUM(orders_count), 2) END',
                isRatio: false,
                numeratorExpression: 'SUM(net_sales)',
                denominatorExpression: 'SUM(orders_count)',
            ),
            'agg_shift' => new MetricImplementation(
                'agg_shift',
                'CASE WHEN SUM(orders_count) = 0 THEN NULL ELSE ROUND(SUM(net_sales) / SUM(orders_count), 2) END',
                isRatio: false,
                numeratorExpression: 'SUM(net_sales)',
                denominatorExpression: 'SUM(orders_count)',
            ),
        ];
        $this->addMetric('avg_ticket', $avgTicketImpl, 'K07', 'متوسط قيمة الطلب / Avg ticket');
        $this->addMetric('K07', $avgTicketImpl, 'K07', 'متوسط قيمة الطلب / Avg ticket');

        // COGS (K08)
        $cogsImpl = [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(cogs), 0)'),
            'agg_product_daily' => new MetricImplementation('agg_product_daily', 'COALESCE(SUM(cogs), 0)'),
        ];
        $this->addMetric('cogs', $cogsImpl, 'K08', 'تكلفة المبيعات / COGS');
        $this->addMetric('K08', $cogsImpl, 'K08', 'تكلفة المبيعات / COGS');

        // Gross Profit (K09)
        $grossProfitImpl = [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(gross_profit), 0)'),
            'agg_product_daily' => new MetricImplementation('agg_product_daily', 'COALESCE(SUM(gross_profit), 0)'),
            'agg_shift' => new MetricImplementation('agg_shift', 'COALESCE(SUM(gross_profit), 0)'),
        ];
        $this->addMetric('gross_profit', $grossProfitImpl, 'K09', 'مجمل الربح / Gross profit');
        $this->addMetric('K09', $grossProfitImpl, 'K09', 'مجمل الربح / Gross profit');

        // Gross Margin (K10) - Derived Ratio
        $grossMarginImpl = [
            'agg_branch_daily' => new MetricImplementation(
                'agg_branch_daily',
                'CASE WHEN SUM(net_sales_after_returns) = 0 THEN NULL ELSE ROUND(SUM(gross_profit) / SUM(net_sales_after_returns), 4) END',
                isRatio: true,
                numeratorExpression: 'SUM(gross_profit)',
                denominatorExpression: 'SUM(net_sales_after_returns)',
            ),
            'agg_product_daily' => new MetricImplementation(
                'agg_product_daily',
                'CASE WHEN SUM(net_sales) = 0 THEN NULL ELSE ROUND(SUM(gross_profit) / SUM(net_sales), 4) END',
                isRatio: true,
                numeratorExpression: 'SUM(gross_profit)',
                denominatorExpression: 'SUM(net_sales)',
            ),
            'agg_shift' => new MetricImplementation(
                'agg_shift',
                'CASE WHEN SUM(net_sales) = 0 THEN NULL ELSE ROUND(SUM(gross_profit) / SUM(net_sales), 4) END',
                isRatio: true,
                numeratorExpression: 'SUM(gross_profit)',
                denominatorExpression: 'SUM(net_sales)',
            ),
        ];
        $this->addMetric('gross_margin', $grossMarginImpl, 'K10', 'هامش مجمل الربح / Gross margin');
        $this->addMetric('K10', $grossMarginImpl, 'K10', 'هامش مجمل الربح / Gross margin');

        // Expenses Total (K11)
        $expensesImpl = [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(expenses_total), 0)'),
            'agg_expense_daily' => new MetricImplementation('agg_expense_daily', 'COALESCE(SUM(amount), 0)'),
        ];
        $this->addMetric('expenses', $expensesImpl, 'K11', 'المصروفات / Expenses');
        $this->addMetric('expenses_total', [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(expenses_total), 0)'),
        ], 'K11', 'المصروفات / Expenses');
        $this->addMetric('K11', $expensesImpl, 'K11', 'المصروفات / Expenses');

        // Labor Cost (K11a)
        $laborImpl = [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(labor_cost), 0)'),
        ];
        $this->addMetric('labor', $laborImpl, 'K11a', 'تكلفة العمالة / Labor');
        $this->addMetric('labor_cost', $laborImpl, 'K11a', 'تكلفة العمالة / Labor');
        $this->addMetric('K11a', $laborImpl, 'K11a', 'تكلفة العمالة / Labor');

        // Rent & Other Expenses
        $this->addMetric('rent_cost', [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(rent_cost), 0)'),
        ], label: 'تكلفة الإيجار / Rent cost');
        $this->addMetric('other_expenses', [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(other_expenses), 0)'),
        ], label: 'مصروفات أخرى / Other expenses');

        // Waste Cost (K12)
        $wasteImpl = [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(waste_cost), 0)'),
            'agg_product_daily' => new MetricImplementation('agg_product_daily', 'COALESCE(SUM(waste_cost), 0)'),
        ];
        $this->addMetric('waste', $wasteImpl, 'K12', 'الهالك / Waste cost');
        $this->addMetric('waste_cost', $wasteImpl, 'K12', 'الهالك / Waste cost');
        $this->addMetric('K12', $wasteImpl, 'K12', 'الهالك / Waste cost');

        // Inventory Variance (K13)
        $invVarImpl = [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(inventory_variance_cost), 0)'),
        ];
        $this->addMetric('inventory_variance', $invVarImpl, 'K13', 'عجز الجرد / Inventory variance');
        $this->addMetric('inventory_variance_cost', $invVarImpl, 'K13', 'عجز الجرد / Inventory variance');
        $this->addMetric('K13', $invVarImpl, 'K13', 'عجز الجرد / Inventory variance');

        // Net Profit (K14)
        $netProfitImpl = [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(net_profit), 0)'),
            'agg_product_daily' => new MetricImplementation('agg_product_daily', 'COALESCE(SUM(gross_profit), 0)'),
        ];
        $this->addMetric('net_profit', $netProfitImpl, 'K14', 'صافي الربح / Net profit');
        $this->addMetric('profit', $netProfitImpl, 'K14', 'صافي الربح / Net profit');
        $this->addMetric('K14', $netProfitImpl, 'K14', 'صافي الربح / Net profit');

        // Net Margin (K15) - Derived Ratio
        $netMarginImpl = [
            'agg_branch_daily' => new MetricImplementation(
                'agg_branch_daily',
                'CASE WHEN SUM(net_sales_after_returns) = 0 THEN NULL ELSE ROUND(SUM(net_profit) / SUM(net_sales_after_returns), 4) END',
                isRatio: true,
                numeratorExpression: 'SUM(net_profit)',
                denominatorExpression: 'SUM(net_sales_after_returns)',
            ),
        ];
        $this->addMetric('net_margin', $netMarginImpl, 'K15', 'هامش الربح / Net margin');
        $this->addMetric('K15', $netMarginImpl, 'K15', 'هامش الربح / Net margin');

        // Food Cost % (K16) - Derived Ratio
        $foodCostImpl = [
            'agg_branch_daily' => new MetricImplementation(
                'agg_branch_daily',
                'CASE WHEN SUM(net_sales_after_returns) = 0 THEN NULL ELSE ROUND((SUM(cogs) - SUM(returned_cogs_recovered)) / SUM(net_sales_after_returns), 4) END',
                isRatio: true,
                numeratorExpression: 'SUM(cogs) - SUM(returned_cogs_recovered)',
                denominatorExpression: 'SUM(net_sales_after_returns)',
            ),
        ];
        $this->addMetric('food_cost_pct', $foodCostImpl, 'K16', 'نسبة تكلفة الخامات / Food cost %');
        $this->addMetric('K16', $foodCostImpl, 'K16', 'نسبة تكلفة الخامات / Food cost %');

        // Waste % (K17) - Derived Ratio
        $wastePctImpl = [
            'agg_branch_daily' => new MetricImplementation(
                'agg_branch_daily',
                'CASE WHEN SUM(net_sales_after_returns) = 0 THEN NULL ELSE ROUND(SUM(waste_cost) / SUM(net_sales_after_returns), 4) END',
                isRatio: true,
                numeratorExpression: 'SUM(waste_cost)',
                denominatorExpression: 'SUM(net_sales_after_returns)',
            ),
        ];
        $this->addMetric('waste_pct', $wastePctImpl, 'K17', 'نسبة الهالك / Waste %');
        $this->addMetric('K17', $wastePctImpl, 'K17', 'نسبة الهالك / Waste %');

        // Purchases (K20)
        $purchasesImpl = [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(purchases_total - purchase_returns_total), 0)'),
            'agg_purchase_daily' => new MetricImplementation('agg_purchase_daily', 'COALESCE(SUM(CASE WHEN kind = "invoice" THEN amount ELSE -amount END), 0)'),
        ];
        $this->addMetric('net_purchases', $purchasesImpl, 'K20', 'صافي المشتريات / Net purchases');
        $this->addMetric('K20', $purchasesImpl, 'K20', 'صافي المشتريات / Net purchases');
        $this->addMetric('purchases_total', [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(purchases_total), 0)'),
            'agg_purchase_daily' => new MetricImplementation('agg_purchase_daily', 'COALESCE(SUM(CASE WHEN kind = "invoice" THEN amount ELSE 0 END), 0)'),
        ], label: 'إجمالي المشتريات / Purchases total');
        $this->addMetric('purchases_amount', [
            'agg_purchase_daily' => new MetricImplementation('agg_purchase_daily', 'COALESCE(SUM(amount), 0)'),
        ], label: 'مبلغ المشتريات / Purchases amount');
        $this->addMetric('purchase_returns_total', [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(purchase_returns_total), 0)'),
            'agg_purchase_daily' => new MetricImplementation('agg_purchase_daily', 'COALESCE(SUM(CASE WHEN kind = "return" THEN amount ELSE 0 END), 0)'),
        ], label: 'مرتجعات المشتريات / Purchase returns');
        $this->addMetric('invoices_count', [
            'agg_purchase_daily' => new MetricImplementation('agg_purchase_daily', 'COALESCE(SUM(invoices_count), 0)'),
        ], label: 'عدد الفواتير / Invoices count');

        // Cash Deficit (K25)
        $cashDeficitImpl = [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(cash_deficit_total), 0)'),
            'agg_shift' => new MetricImplementation('agg_shift', 'COALESCE(SUM(cash_deficit), 0)'),
        ];
        $this->addMetric('cash_deficit', $cashDeficitImpl, 'K25', 'عجز النقدية / Cash deficit');
        $this->addMetric('cash_deficit_total', [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(cash_deficit_total), 0)'),
        ], 'K25', 'عجز النقدية / Cash deficit');
        $this->addMetric('K25', $cashDeficitImpl, 'K25', 'عجز النقدية / Cash deficit');

        // Operational counts
        $this->addMetric('cancelled_count', [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(cancelled_count), 0)'),
            'agg_shift' => new MetricImplementation('agg_shift', 'COALESCE(SUM(cancelled_count), 0)'),
        ], label: 'عدد الطلبات الملغاة / Cancelled orders count');
        $this->addMetric('cancelled_value', [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(cancelled_value), 0)'),
        ], label: 'قيمة الإلغاءات / Cancelled value');
        $this->addMetric('shifts_count', [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(shifts_count), 0)'),
        ], label: 'عدد الورديات / Shifts count');
        $this->addMetric('tax', [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(tax), 0)'),
        ], label: 'الضريبة / Tax');
        $this->addMetric('service', [
            'agg_branch_daily' => new MetricImplementation('agg_branch_daily', 'COALESCE(SUM(service), 0)'),
        ], label: 'الخدمة / Service');

        // Product specific measures
        $qtyImpl = [
            'agg_product_daily' => new MetricImplementation('agg_product_daily', 'COALESCE(SUM(qty_sold), 0)'),
        ];
        $this->addMetric('qty_sold', $qtyImpl, label: 'الكمية المباعة / Quantity sold');
        $this->addMetric('product_qty', $qtyImpl, label: 'الكمية المباعة / Quantity sold');
        $this->addMetric('quantity', $qtyImpl, label: 'الكمية المباعة / Quantity sold');
        $this->addMetric('returns_qty', [
            'agg_product_daily' => new MetricImplementation('agg_product_daily', 'COALESCE(SUM(returns_qty), 0)'),
        ], label: 'كمية المرتجع / Returns quantity');
        $this->addMetric('waste_qty', [
            'agg_product_daily' => new MetricImplementation('agg_product_daily', 'COALESCE(SUM(waste_qty), 0)'),
        ], label: 'كمية الهالك / Waste quantity');

        // Expense specific measures
        $amountImpl = [
            'agg_expense_daily' => new MetricImplementation('agg_expense_daily', 'COALESCE(SUM(amount), 0)'),
            'agg_purchase_daily' => new MetricImplementation('agg_purchase_daily', 'COALESCE(SUM(amount), 0)'),
        ];
        $this->addMetric('amount', $amountImpl, label: 'المبلغ / Amount');
        $this->addMetric('expense_amount', [
            'agg_expense_daily' => new MetricImplementation('agg_expense_daily', 'COALESCE(SUM(amount), 0)'),
        ], label: 'مبلغ المصروفات / Expense amount');
    }

    /**
     * @param  literal-string  $name
     * @param  array<string, MetricImplementation>  $implementations
     * @param  literal-string|null  $kpiCode
     */
    private function addMetric(string $name, array $implementations, ?string $kpiCode = null, ?string $label = null): void
    {
        $this->metrics[$name] = new MetricDefinition($name, $implementations, $kpiCode, $label);
    }

    private function registerDimensions(): void
    {
        // 1. branch
        $this->dimensions['branch'] = new DimensionDefinition(
            'branch',
            [
                'agg_branch_daily' => 'branch_id',
                'agg_product_daily' => 'branch_id',
                'agg_shift' => 'branch_id',
                'agg_branch_hourly' => 'branch_id',
                'agg_expense_daily' => 'branch_id',
                'agg_purchase_daily' => 'branch_id',
            ],
            'branch_id',
            'الفرع / Branch',
        );

        // 2. restaurant
        $this->dimensions['restaurant'] = new DimensionDefinition(
            'restaurant',
            [
                'agg_branch_daily' => 'restaurant_id',
            ],
            'restaurant_id',
            'المطعم / Restaurant',
        );

        // 3. date
        $this->dimensions['date'] = new DimensionDefinition(
            'date',
            [
                'agg_branch_daily' => 'business_date',
                'agg_product_daily' => 'business_date',
                'agg_shift' => 'business_date',
                'agg_branch_hourly' => 'business_date',
                'agg_expense_daily' => 'business_date',
                'agg_purchase_daily' => 'business_date',
            ],
            'date',
            'التاريخ / Date',
        );

        // 4. week (Egyptian week starts Saturday)
        $weekSql = "DATE_FORMAT(DATE_SUB(business_date, INTERVAL (WEEKDAY(business_date) + 2) % 7 DAY), '%Y-%m-%d')";
        $this->dimensions['week'] = new DimensionDefinition(
            'week',
            [
                'agg_branch_daily' => $weekSql,
                'agg_product_daily' => $weekSql,
                'agg_shift' => $weekSql,
                'agg_branch_hourly' => $weekSql,
                'agg_expense_daily' => $weekSql,
                'agg_purchase_daily' => $weekSql,
            ],
            'week',
            'الأسبوع / Week',
        );

        // 5. month
        $monthSql = "DATE_FORMAT(business_date, '%Y-%m')";
        $this->dimensions['month'] = new DimensionDefinition(
            'month',
            [
                'agg_branch_daily' => $monthSql,
                'agg_product_daily' => $monthSql,
                'agg_shift' => $monthSql,
                'agg_branch_hourly' => $monthSql,
                'agg_expense_daily' => $monthSql,
                'agg_purchase_daily' => $monthSql,
            ],
            'month',
            'الشهر / Month',
        );

        // 6. weekday (0=Saturday .. 6=Friday)
        $this->dimensions['weekday'] = new DimensionDefinition(
            'weekday',
            [
                'agg_branch_daily' => 'weekday',
                'agg_shift' => 'weekday',
                'agg_branch_hourly' => 'weekday',
            ],
            'weekday',
            'يوم الأسبوع / Weekday',
        );

        // 7. hour
        $this->dimensions['hour'] = new DimensionDefinition(
            'hour',
            [
                'agg_branch_hourly' => 'hour',
            ],
            'hour',
            'الساعة / Hour',
        );

        // 8. product
        $this->dimensions['product'] = new DimensionDefinition(
            'product',
            [
                'agg_product_daily' => 'COALESCE(product_ref, product_uuid)',
            ],
            'product_key',
            'الصنف / Product',
        );

        // 9. category
        $this->dimensions['category'] = new DimensionDefinition(
            'category',
            [
                'agg_product_daily' => 'category_uuid',
            ],
            'category_uuid',
            'التصنيف / Category',
        );

        // 10. expense_category
        $this->dimensions['expense_category'] = new DimensionDefinition(
            'expense_category',
            [
                'agg_expense_daily' => 'category',
            ],
            'expense_category',
            'تصنيف المصروف / Expense Category',
        );

        // 11. supplier
        $this->dimensions['supplier'] = new DimensionDefinition(
            'supplier',
            [
                'agg_purchase_daily' => 'supplier_uuid',
            ],
            'supplier_uuid',
            'المورد / Supplier',
        );

        // 12. shift
        $this->dimensions['shift'] = new DimensionDefinition(
            'shift',
            [
                'agg_shift' => 'shift_uuid',
            ],
            'shift_uuid',
            'الوردية / Shift',
        );

        // 13. cashier
        $this->dimensions['cashier'] = new DimensionDefinition(
            'cashier',
            [
                'agg_shift' => 'cashier_uuid',
            ],
            'cashier_uuid',
            'الكاشير / Cashier',
        );

        // 14. channel (Recognized whitelist dimension, but not supported by any agg SQL table)
        $this->dimensions['channel'] = new DimensionDefinition(
            'channel',
            [],
            'channel',
            'القناة / Channel',
        );

        // 15. payment_method (Recognized whitelist dimension, but not supported by any agg SQL table)
        $this->dimensions['payment_method'] = new DimensionDefinition(
            'payment_method',
            [],
            'payment_method',
            'طريقة الدفع / Payment Method',
        );
    }
}
