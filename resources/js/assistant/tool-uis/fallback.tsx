import { Component, type ErrorInfo, type ReactNode } from "react";
import { AlertCircleIcon, Loader2Icon } from "lucide-react";

export const TOOL_LABELS: Record<string, string> = {
  get_kpis: "⏳ بحسب المؤشرات…",
  get_kpi_series: "⏳ بحلل الاتجاهات…",
  query_metrics: "⏳ بستعلم عن البيانات…",
  overview_report: "⏳ بجهز التقرير الشامل…",
  sales_comparison: "⏳ بقارن المبيعات…",
  profitability: "⏳ بحلل الربحية…",
  food_cost_waste: "⏳ بحسب تكلفة الخامات والهالك…",
  product_performance: "⏳ بحلل أداء الأصناف…",
  shifts_weekdays: "⏳ بحلل الورديات والأيام…",
  branch_ranking: "⏳ برتب الفروع…",
  targets_status: "⏳ بفحص المستهدفات…",
  expenses_purchases: "⏳ براجع المصروفات والمشتريات…",
  data_health: "⏳ بفحص صحة البيانات…",
  analyze_growth: "⏳ بحلل النمو…",
  detect_anomalies: "⏳ بفحص التغيرات غير المعتادة…",
  analyze_profit_contribution: "⏳ بحلل مساهمة الأرباح…",
  analyze_cost_ratios: "⏳ بحلل نسب التكاليف…",
  forecast_sales: "⏳ بحسب التوقعات…",
  forecast_material_needs: "⏳ بتوقع احتياجات المواد…",
  menu_engineering: "⏳ بهندس قائمة الطعام…",
  get_workspace_context: "⏳ بجهز سياق العمل…",
  resolve_period: "⏳ بحدد الفترة الزمنية…",
  find_branches: "⏳ ببحث عن الفروع…",
  search_products: "⏳ ببحث عن الأصناف…",
  rank_items: "⏳ برتب الأصناف اللي تستاهل تركيز…",
  get_combo_candidates: "⏳ بجيب العروض الجاهزة بهامش ربح آمن…",
  get_item_card: "⏳ بجهز كارت الصنف…",
  get_affinities: "⏳ بشوف الأصناف اللي بتتطلب مع بعض…",
  default: "⏳ بجهز البيانات…",
};

export function extractToolReason(payload: unknown): string | undefined {
  if (!payload || typeof payload !== "object") return undefined;
  const p = payload as Record<string, unknown>;
  const metrics = p.metrics as Record<string, unknown> | undefined;

  if (typeof metrics?.reason === "string") return metrics.reason;
  if (typeof p.reason === "string") return p.reason;
  if (typeof p.message_ar === "string") return p.message_ar;
  if (typeof p.summary === "string") return p.summary;
  if (typeof p.error === "string") return "حدث خطأ أثناء معالجة بيانات الأداة.";
  return undefined;
}

export interface FallbackCardProps {
  toolName?: string;
  reason?: string;
  payload?: unknown;
}

export function FallbackCard({ reason, payload }: FallbackCardProps) {
  const extractedReason = reason ?? extractToolReason(payload);

  return (
    <div
      dir="rtl"
      className="w-full rounded-xl border border-amber-500/30 bg-amber-500/5 p-4 text-foreground shadow-xs"
    >
      <div className="flex items-center gap-2 font-medium text-amber-700 dark:text-amber-400">
        <AlertCircleIcon className="size-4 shrink-0" />
        <span>مقدرتش أعرض التفاصيل دي</span>
      </div>
      {extractedReason && (
        <p className="mt-1.5 text-xs text-muted-foreground pe-6">
          {extractedReason}
        </p>
      )}
    </div>
  );
}

export interface RunningSkeletonProps {
  toolName?: string;
}

export function RunningSkeleton({ toolName }: RunningSkeletonProps) {
  const label = (toolName && TOOL_LABELS[toolName]) || TOOL_LABELS.default;

  return (
    <div
      dir="rtl"
      className="w-full rounded-xl border border-border/60 bg-card p-4 space-y-3 shadow-xs"
    >
      <div className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
        <Loader2Icon className="size-4 animate-spin text-primary" />
        <span>{label}</span>
      </div>
      <div className="space-y-2">
        <div className="h-4 w-2/3 animate-pulse rounded bg-muted/60" />
        <div className="h-16 w-full animate-pulse rounded bg-muted/40" />
      </div>
    </div>
  );
}

export interface ToolErrorBoundaryProps {
  toolName?: string;
  fallback?: ReactNode;
  children: ReactNode;
}

interface ToolErrorBoundaryState {
  hasError: boolean;
}

export class ToolErrorBoundary extends Component<
  ToolErrorBoundaryProps,
  ToolErrorBoundaryState
> {
  constructor(props: ToolErrorBoundaryProps) {
    super(props);
    this.state = { hasError: false };
  }

  static getDerivedStateFromError(): ToolErrorBoundaryState {
    return { hasError: true };
  }

  componentDidCatch(_error: Error, _errorInfo: ErrorInfo): void {
    // Catch rendering exception safely
  }

  render(): ReactNode {
    if (this.state.hasError) {
      return this.props.fallback ?? <FallbackCard toolName={this.props.toolName} />;
    }
    return this.props.children;
  }
}
