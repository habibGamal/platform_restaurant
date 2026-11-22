import { useMemo, useState } from "react";
import { flexRender } from "@tanstack/react-table";
import type { ExpandedState } from "@tanstack/table-core";
import {
  useLegacyTable,
  getCoreRowModel,
  getExpandedRowModel,
  type LegacyColumnDef,
} from "@tanstack/react-table/legacy";
import { ChevronDownIcon, ChevronUpIcon } from "lucide-react";
import type {
  RankItemsResult,
  RankedItem,
  RankItemsSegment,
  ToolPartProps,
} from "../types";
import {
  FallbackCard,
  RunningSkeleton,
  ToolErrorBoundary,
} from "./fallback";

const FACTOR_LABELS: Record<string, string> = {
  unit_margin: "هامش الوحدة",
  popularity: "الشعبية والإقبال",
  headroom: "فرصة التوسع",
  momentum: "الزخم والنمو",
  season_fit: "ملاءمة الموسم",
  repeat: "تكرار الطلب",
  low_margin_pct: "انخفاض نسبة الهامش",
  low_popularity: "ضعف الإقبال",
  low_unit_margin: "انخفاض هامش الوحدة",
  low_momentum: "ضعف الزخم",
};

const QUADRANT_LABELS: Record<string, { label: string; className: string }> = {
  star: { label: "نجم", className: "bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/20" },
  plowhorse: { label: "حصان عمل", className: "bg-blue-500/10 text-blue-700 dark:text-blue-400 border-blue-500/20" },
  puzzle: { label: "لغز", className: "bg-purple-500/10 text-purple-700 dark:text-purple-400 border-purple-500/20" },
  dog: { label: "عبء", className: "bg-rose-500/10 text-rose-700 dark:text-rose-400 border-rose-500/20" },
};

function SegmentTable({ segment }: { segment: RankItemsSegment }) {
  const [expanded, setExpanded] = useState<ExpandedState>({});

  const columns = useMemo<LegacyColumnDef<RankedItem>[]>(
    () => [
      {
        accessorKey: "rank",
        header: () => <span className="text-muted-foreground font-semibold">#</span>,
        cell: (info) => (
          <span className="font-mono font-bold text-foreground">
            {Number(info.getValue())}
          </span>
        ),
      },
      {
        accessorKey: "name",
        header: () => <span>الصنف</span>,
        cell: ({ row }) => {
          const item = row.original;
          const quadrant = QUADRANT_LABELS[item.quadrant];
          return (
            <div className="space-y-0.5">
              <div className="flex items-center gap-2">
                <span className="font-medium text-foreground">{item.name}</span>
                {quadrant && (
                  <span
                    className={`inline-flex items-center rounded-sm border px-1.5 py-0.2 text-[10px] font-medium ${quadrant.className}`}
                  >
                    {quadrant.label}
                  </span>
                )}
              </div>
              <div className="text-[11px] font-mono text-muted-foreground">
                {item.item_key}
              </div>
            </div>
          );
        },
      },
      {
        accessorKey: "qty",
        header: () => <span>الكمية</span>,
        cell: (info) => (
          <span className="font-mono text-muted-foreground">
            {Number(info.getValue()).toLocaleString("en-US")}
          </span>
        ),
      },
      {
        accessorKey: "net_sales",
        header: () => <span>صافي المبيعات</span>,
        cell: (info) => (
          <span className="font-mono font-medium text-foreground">
            {`${Number(info.getValue()).toLocaleString("en-US")} ج.م`}
          </span>
        ),
      },
      {
        accessorKey: "margin_pct",
        header: () => <span>نسبة الهامش</span>,
        cell: (info) => {
          const val = info.getValue() as number | null;
          if (val === null) return <span className="text-muted-foreground">-</span>;
          const pct = (val * 100).toFixed(1);
          return (
            <span
              className={`font-mono font-medium ${
                val >= 0.5
                  ? "text-emerald-600 dark:text-emerald-400"
                  : val >= 0.3
                  ? "text-foreground"
                  : "text-rose-600 dark:text-rose-400"
              }`}
            >
              {`${pct}%`}
            </span>
          );
        },
      },
      {
        accessorKey: "score",
        header: () => <span>النقاط</span>,
        cell: ({ row }) => {
          const score = row.original.score;
          const hasFactors = !!row.original.factors && Object.keys(row.original.factors).length > 0;
          const isExpanded = row.getIsExpanded();

          return (
            <div className="flex items-center justify-between gap-1">
              <span className="font-mono font-semibold text-primary">
                {typeof score === "number" ? score.toFixed(1) : score}
              </span>
              {hasFactors && (
                <button
                  type="button"
                  onClick={(e) => {
                    e.stopPropagation();
                    row.toggleExpanded();
                  }}
                  className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                  title="عرض تفاصيل التقييم"
                >
                  {isExpanded ? (
                    <ChevronUpIcon className="size-3.5" />
                  ) : (
                    <ChevronDownIcon className="size-3.5" />
                  )}
                </button>
              )}
            </div>
          );
        },
      },
    ],
    []
  );

  const table = useLegacyTable({
    data: segment.items,
    columns,
    state: { expanded },
    onExpandedChange: (updater) => {
      setExpanded((prev) => (typeof updater === "function" ? updater(prev) : updater));
    },
    getCoreRowModel: getCoreRowModel(),
    getExpandedRowModel: getExpandedRowModel(),
    getRowId: (row) => row.item_key,
  });

  return (
    <div className="overflow-x-auto rounded-lg border border-border/60 bg-card">
      <table className="w-full border-collapse text-start text-xs">
        <thead>
          {table.getHeaderGroups().map((headerGroup) => (
            <tr
              key={headerGroup.id}
              className="border-b border-border/60 bg-muted/30 text-start text-muted-foreground"
            >
              {headerGroup.headers.map((header) => (
                <th key={header.id} className="p-2.5 text-start font-medium">
                  {header.isPlaceholder
                    ? null
                    : flexRender(header.column.columnDef.header, header.getContext())}
                </th>
              ))}
            </tr>
          ))}
        </thead>
        <tbody className="divide-y divide-border/40">
          {table.getRowModel().rows.map((row) => (
            <tr
              key={row.id}
              onClick={() => row.toggleExpanded()}
              className="group cursor-pointer transition-colors hover:bg-muted/40"
            >
              {row.getVisibleCells().map((cell) => (
                <td key={cell.id} className="p-2.5 text-start align-middle">
                  {flexRender(cell.column.columnDef.cell, cell.getContext())}
                </td>
              ))}
            </tr>
          ))}
          {table.getRowModel().rows.map((row) => {
            if (!row.getIsExpanded() || !row.original.factors) return null;
            const factors = row.original.factors;

            return (
              <tr key={`${row.id}-expanded`} className="bg-muted/15 border-b border-border/40">
                <td colSpan={columns.length} className="p-3">
                  <div className="space-y-2">
                    <div className="text-[11px] font-semibold text-muted-foreground">
                      تفاصيل نقاط تقييم الصنف ({row.original.name}):
                    </div>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                      {Object.entries(factors).map(([factor, val]) => {
                        const numericVal = typeof val === "number" ? val : Number(val);
                        const pct = Math.min(Math.max(Math.round(numericVal * 100), 0), 100);
                        return (
                          <div key={factor} className="space-y-1">
                            <div className="flex justify-between text-[11px] text-muted-foreground">
                              <span>{FACTOR_LABELS[factor] ?? factor}</span>
                              <span className="font-mono">{numericVal.toFixed(2)}</span>
                            </div>
                            <div className="h-1.5 w-full rounded-full bg-muted/60 overflow-hidden">
                              <div
                                className="h-full rounded-full bg-primary transition-all duration-300"
                                style={{ width: `${pct}%` }}
                              />
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

export function RankItemsCard({ result, status }: ToolPartProps<Record<string, unknown>, unknown>) {
  if (status?.type === "running" || result === undefined) {
    return <RunningSkeleton toolName="rank_items" />;
  }

  let payload: RankItemsResult;
  try {
    payload = typeof result === "string" ? JSON.parse(result) : (result as RankItemsResult);
  } catch {
    return <FallbackCard toolName="rank_items" reason="تعذر قراءة بيانات الترتيب." />;
  }

  if (
    !payload ||
    typeof payload !== "object" ||
    (payload as Record<string, unknown>).raw !== undefined ||
    (payload as Record<string, unknown>).error !== undefined ||
    payload.status === "insufficient_data" ||
    payload.metrics?.status === "insufficient_data"
  ) {
    return <FallbackCard toolName="rank_items" payload={payload} />;
  }

  const segments = payload.metrics?.segments;
  if (!segments || typeof segments !== "object" || Object.keys(segments).length === 0) {
    return (
      <FallbackCard
        toolName="rank_items"
        payload={payload}
        reason="مفيش مجموعات أصناف متاحة للعرض."
      />
    );
  }

  return (
    <ToolErrorBoundary toolName="rank_items">
      <div dir="rtl" className="w-full space-y-4">
        {payload.summary && (
          <div className="text-xs font-medium text-muted-foreground px-1">
            {payload.summary}
          </div>
        )}
        {Object.entries(segments).map(([segmentKey, segment]) => (
          <div
            key={segmentKey}
            className="rounded-xl border border-border/60 bg-card/60 p-3 shadow-xs space-y-2.5"
          >
            <div className="flex items-center justify-between gap-2 border-b border-border/40 pb-2">
              <h4 className="text-sm font-bold text-foreground">
                {segment.label || segmentKey}
              </h4>
              <span className="text-[11px] text-muted-foreground">
                {`${segment.items.length} صنف`}
              </span>
            </div>
            <SegmentTable segment={segment} />
          </div>
        ))}
      </div>
    </ToolErrorBoundary>
  );
}
