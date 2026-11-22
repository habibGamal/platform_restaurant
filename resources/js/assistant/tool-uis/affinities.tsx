import { FlameIcon } from "lucide-react";
import type {
  AffinitiesResult,
  ToolPartProps,
} from "../types";
import {
  FallbackCard,
  RunningSkeleton,
  ToolErrorBoundary,
} from "./fallback";

export function AffinitiesCard({ result, status }: ToolPartProps<Record<string, unknown>, unknown>) {
  if (status?.type === "running" || result === undefined) {
    return <RunningSkeleton toolName="get_affinities" />;
  }

  let payload: AffinitiesResult;
  try {
    payload = typeof result === "string" ? JSON.parse(result) : (result as AffinitiesResult);
  } catch {
    return <FallbackCard toolName="get_affinities" reason="تعذر قراءة بيانات الأصناف المترابطة." />;
  }

  if (
    !payload ||
    typeof payload !== "object" ||
    (payload as Record<string, unknown>).raw !== undefined ||
    (payload as Record<string, unknown>).error !== undefined ||
    payload.status === "insufficient_data" ||
    payload.metrics?.status === "insufficient_data" ||
    payload.metrics?.status === "not_found" ||
    payload.metrics?.status === "ambiguous"
  ) {
    return <FallbackCard toolName="get_affinities" payload={payload} />;
  }

  const itemsets = payload.metrics?.itemsets;
  if (!itemsets || !Array.isArray(itemsets) || itemsets.length === 0) {
    return (
      <FallbackCard
        toolName="get_affinities"
        payload={payload}
        reason="مفيش مجموعات مترابطة كفاية للعرض في الفترة دي."
      />
    );
  }

  const mainItemName = payload.metrics?.item?.name;
  const period = payload.metrics?.affinity_period;

  return (
    <ToolErrorBoundary toolName="get_affinities">
      <div
        dir="rtl"
        className="w-full rounded-xl border border-border/60 bg-card p-4 shadow-xs space-y-3 text-foreground"
      >
        {/* Header */}
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border/40 pb-2.5">
          <div className="flex items-center gap-2">
            <FlameIcon className="size-4 text-amber-500 shrink-0" />
            <h4 className="text-sm font-bold text-foreground">
              {mainItemName ? `أصناف بتتطلب مع ${mainItemName}` : "الأصناف الأكثر طلباً معاً"}
            </h4>
          </div>
          {period && (
            <span className="text-xs font-mono text-muted-foreground bg-muted/40 px-2 py-0.5 rounded">
              {`فترة: ${period}`}
            </span>
          )}
        </div>

        {/* Itemsets List */}
        <div className="divide-y divide-border/40">
          {itemsets.map((itemset, idx) => {
            const names = itemset.items.map((i) => i.name).join(" + ");
            const liftFormatted = Number(itemset.lift).toFixed(1);
            const isHighLift = itemset.lift >= 2.0;

            return (
              <div
                key={idx}
                className="py-2.5 first:pt-1 last:pb-1 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs"
              >
                <div className="space-y-0.5">
                  <div className="font-semibold text-foreground text-sm">
                    {names}
                  </div>
                  {itemset.avg_order_value && (
                    <div className="text-[11px] text-muted-foreground">
                      {`متوسط الطلب: ${Math.round(itemset.avg_order_value).toLocaleString("en-US")} ج.م`}
                    </div>
                  )}
                </div>

                {/* together_orders and lift always shown next to each other */}
                <div className="flex items-center gap-2 shrink-0">
                  <span className="inline-flex items-center rounded-md border border-border/60 bg-muted/40 px-2.5 py-1 font-medium text-foreground">
                    {`${itemset.together_orders.toLocaleString("en-US")} طلب مع بعض`}
                  </span>

                  <span
                    className={`inline-flex items-center rounded-md border px-2.5 py-1 font-mono font-bold ${
                      isHighLift
                        ? "border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400"
                        : "border-blue-500/30 bg-blue-500/10 text-blue-700 dark:text-blue-400"
                    }`}
                  >
                    {`الرفع (Lift): ${liftFormatted}x`}
                  </span>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </ToolErrorBoundary>
  );
}
