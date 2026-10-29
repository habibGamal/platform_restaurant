# Platform Restaurants — Plans

| File | What |
|---|---|
| [00-architecture.md](00-architecture.md) | System architecture: ingestion contract, pipeline, data model, AI module, phases |
| [reports/00-conventions.md](reports/00-conventions.md) | Shared rules: periods, comparisons, **KPI formulas (K01–K25)**, completeness, UI, test fixture |
| [reports/01-group-overview-dashboard.md](reports/01-group-overview-dashboard.md) | لوحة التحكم الرئيسية |
| [reports/02-sales-comparison.md](reports/02-sales-comparison.md) | المبيعات وعدد الطلبات ومتوسط الطلب |
| [reports/03-profitability-comparison.md](reports/03-profitability-comparison.md) | الأرباح وهوامش الربح |
| [reports/04-food-cost-and-waste.md](reports/04-food-cost-and-waste.md) | تكلفة الخامات ونسبة الهالك |
| [reports/05-product-performance.md](reports/05-product-performance.md) | الأصناف الأكثر والأقل مبيعاً |
| [reports/06-shifts-weekdays-hours.md](reports/06-shifts-weekdays-hours.md) | الورديات وأيام الأسبوع وساعات الذروة |
| [reports/07-branch-ranking.md](reports/07-branch-ranking.md) | ترتيب المطاعم |
| [reports/08-targets-vs-actual.md](reports/08-targets-vs-actual.md) | المستهدف مقابل المحقق |
| [reports/09-expenses-and-purchases.md](reports/09-expenses-and-purchases.md) | المصروفات والمشتريات |
| [reports/10-branch-sync-health.md](reports/10-branch-sync-health.md) | حالة اتصال الفروع وجودة البيانات |
| [reports/11-ai-growth-analysis.md](reports/11-ai-growth-analysis.md) | AI: تحليل النمو |
| [reports/12-ai-anomaly-detection.md](reports/12-ai-anomaly-detection.md) | AI: اكتشاف الانحرافات |
| [reports/13-ai-profit-contribution.md](reports/13-ai-profit-contribution.md) | AI: مساهمة الفروع في الربح |
| [reports/14-ai-cost-ratios.md](reports/14-ai-cost-ratios.md) | AI: العمالة والخامات كنسبة من المبيعات |
| [reports/15-ai-forecasting.md](reports/15-ai-forecasting.md) | AI: توقع المبيعات والاحتياجات |
| [reports/16-ai-menu-suggestions.md](reports/16-ai-menu-suggestions.md) | AI: اقتراحات التسعير والإيقاف |
| [reports/17-ai-daily-whatsapp-report.md](reports/17-ai-daily-whatsapp-report.md) | AI: التقرير اليومي على WhatsApp |
| [reports/18-ai-alerts.md](reports/18-ai-alerts.md) | التنبيهات |
| [features/access-control.md](features/access-control.md) | الإدارة المركزية والصلاحيات |
| [features/targets-management.md](features/targets-management.md) | تحديد الأهداف |

POS side (larament): `D:\ai_rewrite_turbo_restaurant\larament\.plan\platform-connector-plan.md`

## Build order
Phase 0 ingestion → Phase 1 reports 01–10 + features → Phase 2 alerts 18 (rule-based) + WhatsApp → Phase 3 AI 11–17.
