# Domain Glossary

Canonical mapping between English code names, Arabic UI terms (from Filament panels, reports, and POS/platform specs), Arabic meanings, and technical meanings.

| English code name | Arabic UI word | Arabic meaning | Meaning |
|---|---|---|---|
| `organization` (`Organization`) | منظمة | المجموعة أو الشركة المالكة للعلامات والفروع | Top-level tenant entity holding brands, users, AI settings, and billing budgets. |
| `restaurant` (`Restaurant`) | مطعم | العلامة التجارية أو المفهوم داخل المنظمة | Distinct restaurant brand/concept belonging to an organization. |
| `branch` (`Branch`) | فرع | موقع مطعم فعلي متصل بنقطة بيع | Physical operating restaurant branch paired with one decentralized `larament` POS install. |
| `tenant` (linked POS website) | مستأجر | نسخة المتجر الإلكتروني المرتبطة بالفرع | Brand/store instance on the online ordering website (`turbo_tenant`) placing web orders. |
| `terminal` (POS workstation) | منفذ بيع (never درج) | نقطة الكاشير التي تُفتح عليها الوردية | Cashier workstation / sales point on the paired POS with its own opening cash and session. |
| `shift` (`WhShift`, `AggShift`) | وردية | فترة عمل الكاشير من الفتح إلى الإغلاق | Cashier shift with `start_at`, `end_at`, `start_cash`, `end_cash`, `real_cash`, and `cash_deficit`. |
| `web_order` (`web_delivery`, `web_takeaway`) | طلب الموقع | طلب وارد عبر الموقع الإلكتروني | Online order placed via the linked website and recorded on the branch POS (carries `web_pos_diff`). |
| `branch_set` (`BranchSet`) | مجموعة فروع | مجموعة محفوظة للمقارنة بين فروع مختارة | Saved group of branches (org-wide or personal) selectable in the topbar scope filter. |
| `pairing_code` (`BranchPairingCode`) | رمز الربط | رمز من 8 خانات صالح 15 دقيقة لربط الفرع | One-time 8-character cryptographic pairing code redeemed at `POST /api/v1/pair`. |
| `business_date` | تاريخ العمل | يوم التشغيل الفعلي حتى لو امتد العمل بعد منتصف الليل | POS-owned trading date (`YYYY-MM-DD`, `Africa/Cairo`); all reports group by `business_date`. |
| `weekday` (`App\Support\Weekday`) | يوم الأسبوع | يوم الأسبوع التجاري من السبت (0) إلى الجمعة (6) | Egyptian business weekday index (`0` = Saturday through `6` = Friday). |
| `like_for_like` (`likeForLike`) | مقارنة نفس الفروع | حصر المقارنة في الفروع التي لها بيانات في الفترتين | Filter keeping only branches present in `agg_branch_daily` for both current and comparison periods. |
| `period_preset` (`PeriodPreset`) | الفترة | النطاق الزمني للتقرير (اليوم، هذا الشهر، إلخ) | Named date range preset (`today`, `yesterday`, `this_week`, `last_week`, `this_month`, `last_month`, `this_quarter`, `this_year`, `last_30_days`, `last_8_weeks`, `custom`). |
| `comparison_mode` (`ComparisonMode`) | المقارنة | الفترة المرجعية للمقارنة | Reference baseline (`previous`, `same_weekday_last_week` -7d, `same_period_last_year` -364d, `none`). |
| `orders_count` (`K01`) | عدد الطلبات | إجمالي الطلبات المكتملة | Count of completed orders (`status = completed` and `deleted_at IS NULL`). |
| `gross_sales` (`K02`) | إجمالي المبيعات قبل الخصم | مجموع قيمة الطلبات قبل الخصومات | Sum of `wh_orders.sub_total` for completed orders. |
| `discounts` (`K03`) | الخصومات | مجموع خصومات الطلب وخصومات الأصناف | Sum of order-level `discount` and line-level `items_discount`. |
| `net_sales` (`K04`) | صافي المبيعات | المبيعات بعد الخصم وبدون الضريبة | Sum of `(total - tax)` for completed orders (service charge stays in revenue; tax does not). |
| `returns_amount` (`K05`) | المرتجعات | إجمالي مبالغ استرداد الطلبات | Sum of `wh_order_returns.total_refund` attributed to the return's `business_date`. |
| `net_sales_after_returns` (`K06`) | المبيعات | صافي المبيعات بعد خصم المرتجعات (المؤشر الرئيسي) | Headline sales KPI (`K04 - K05`). |
| `avg_ticket` (`K07`) | متوسط قيمة الطلب | متوسط قيمة الفاتورة لكل طلب | `net_sales / orders_count` (`null` if `orders_count = 0`). |
| `cogs` (`K08`) | تكلفة المبيعات | تكلفة الأصناف المباعة مطروحاً منها المسترد للمخزون | `cogs - returned_cogs_recovered` (where `reverse_stock = true`). |
| `gross_profit` (`K09`) | مجمل الربح | المبيعات مطروحاً منها تكلفة المبيعات | `net_sales_after_returns - (cogs - returned_cogs_recovered)`. |
| `gross_margin` (`K10`) | هامش مجمل الربح | نسبة مجمل الربح إلى المبيعات | `gross_profit / net_sales_after_returns`. |
| `expenses_total` (`K11`) | المصروفات | إجمالي المصروفات التشغيلية المسجلة | Sum of `wh_expenses.amount` across all expense categories. |
| `labor_cost` (`K11a`) | تكلفة العمالة | مصروفات الأجور والرواتب | Direct expenses mapped to category `labor` (with optional monthly allocation in `AllocatedCost`). |
| `waste_cost` (`K12`) | الهالك | قيمة التالف والفاقد المعتمد | Sum of closed `wh_wastes.total`. |
| `inventory_variance_cost` (`K13`) | عجز الجرد | فرق الجرد الفعلي عن الدفتري (الموجب = عجز، السالب = فائض) | Sum of `(stock_quantity - real_quantity) * price` from closed stocktakings; positive decreases profit. |
| `net_profit` (`K14`) | صافي الربح | مجمل الربح بعد خصم المصروفات والهالك وعجز الجرد | `gross_profit - expenses_total - waste_cost - inventory_variance_cost`. |
| `net_margin` (`K15`) | هامش الربح | نسبة صافي الربح إلى المبيعات | `net_profit / net_sales_after_returns`. |
| `food_cost_pct` (`K16`) | نسبة تكلفة الخامات | نسبة تكلفة المبيعات إلى المبيعات | `(cogs - returned_cogs_recovered) / net_sales_after_returns`. |
| `waste_pct` (`K17`) | نسبة الهالك | نسبة تكلفة الهالك إلى المبيعات | `waste_cost / net_sales_after_returns` (secondary usage share `K17b`: `waste / (net_cogs + waste)`). |
| `labor_pct` (`K18`) | نسبة العمالة | تكلفة العمالة المحمّلة إلى المبيعات | `allocated_labor / net_sales_after_returns`. |
| `prime_cost_pct` (`K19`) | نسبة التكلفة الأولية (Prime Cost) | مجموع تكلفة الخامات والعمالة إلى المبيعات | `(net_cogs + allocated_labor) / net_sales_after_returns`. |
| `net_purchases` (`K20`) | صافي المشتريات | فواتير الشراء ناقص مرتجعات الشراء (للتدفق النقدي فقط) | `purchases_total - purchase_returns_total`; informational cash-flow metric, excluded from profit. |
| `cancel_rate` (`K21`) | نسبة الإلغاء | نسبة الطلبات الملغاة من إجمالي الطلبات | `cancelled_count / (orders_count + cancelled_count)`. |
| `return_rate` (`K22`) | نسبة المرتجع | نسبة قيمة المرتجعات إلى صافي المبيعات | `returns_amount / net_sales`. |
| `target_achievement` (`K23`) | نسبة تحقيق المستهدف | المحقق الفعلي مقارنة بالمستهدف حتى تاريخه | `actual_to_date / target_to_date` (or ceiling check for `food_cost_pct`). |
| `growth` (`K24`) | النمو | نسبة التغير مقارنة بفترة مرجعية | `(current - previous) / |previous|` (`null` when `previous` is `null` or `0`). |
| `cash_deficit_total` (`K25`) | عجز النقدية | إجمالي العجز النقدي في الورديات المغلقة | Sum of `max(0, end_cash - real_cash)` across closed shifts. |
| `target` (`Target`) | هدف / تحديد الأهداف | المستهدف الشهري للفرع موزّعاً يومياً | Monthly branch target (`net_sales`, `net_profit`, `orders`, `food_cost_pct`) distributed into `target_daily`. |
| `calendar_event` (`CalendarEvent`) | حدث تقويم | مناسبة أو موسم يُستبعد من الأوزان التاريخية | Holiday/event (`ramadan`, `eid`, `holiday`, `promo`) excluded from 8-week weekday baselines. |
| `scoring_profile` (`ScoringProfile`) | معايير التقييم | أوزان المؤشرات السبعة لترتيب الفروع | Organization weights across 7 KPIs summing to 100 for `BranchRankingReport`. |
| `expense_category_mapping` (`DimExpenseType`) | تصنيفات المصروفات | ربط بنود المصروفات بتصنيف قياسي | Platform override (`category_override`) and `avg_month_rate` monthly budget on `dim_expense_types`. |
| `data_health` (`DataHealth`) | حالة البيانات والاتصال | حالة اتصال الفروع وتسوية اليوميات | Report monitoring heartbeats, completeness calendar, `reconciliation_issues`, and `ingest_rejections`. |
| `assistant` (`RestaurantAnalyst`) | المساعد الذكي | المحلل الذكي متعدد الوكلاء | Grounded multi-agent analyst (`RestaurantAnalyst` + specialists) at `/assistant` and `/app/assistant`. |
| `item_roles` (`ItemRolesPage`) | أدوار الأصناف | تصنيف الأصناف (نجوم، أحصنة جر، ألغاز، كلاب) | BCG-style item role classification (`dim_item_roles`) with manual owner overrides. |
| `item_cards` (`ItemCardsPage`) | بطاقات الأصناف | بطاقة الصنف: السعة وفئة السعر والموسم والمكونات | Precomputed `item_profiles` (`serves_n`, `price_tier`, `season`, `contains`, `main_protein`). |
| `component_classes` (`ComponentClassesPage`) | تصنيف الخامات | تصنيف مكونات الوصفات (بروتين، نشويات، مشروب...) | `FoodClass` classification (`component_classes`) with `owner > auto > rule` trust hierarchy. |
| `combo_candidates` (`ComboCandidatesPage`) | الكومبوهات المقترحة | عروض الوجبات المجمعة المقترحة للمراجعة والاعتماد | Algorithmically generated bundles (`combo_candidates`) reviewed and approved/rejected by operators. |
| `roles` (`operator`, `org_owner`, `org_analyst`, `branch_manager`) | مشغّل / مالك / محلل / مدير فرع | أدوار المستخدمين وصلاحيات الوصول | Spatie RBAC roles scoping `/admin` (`operator`) and `/app` (`org_owner`, `org_analyst`, `branch_manager`). |
